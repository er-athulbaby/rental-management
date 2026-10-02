<?php

use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SubmitAgreement;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Actions\Digests\SendDigests;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\Cheque;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Owner;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\Digest;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-06-08 07:00', 'Asia/Bahrain')); // a Monday
    [$this->mine, $this->theirs] = Building::factory()->count(2)->create()->all();
    $held = function (Building $b, string $date) {
        $customer = Customer::factory()->create();
        $agreement = activeAgreement(['customer_id' => $customer->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [Unit::factory()->for($b)->create()]);
        (new Cheque)->forceFill(['direction' => 'received', 'customer_id' => $customer->id, 'agreement_id' => $agreement->id, 'cheque_no' => uniqid(), 'bank_name' => 'NBB',
            'cheque_date' => $date, 'amount' => '100.000', 'status' => 'held', 'created_by' => User::factory()->create()->id])->save();
    };
    $held($this->mine, '2026-06-08');   // today
    $held($this->mine, '2026-06-12');   // this week
    $held($this->theirs, '2026-06-08'); // another building

    $role = Role::create(['name' => 'Cashier', 'guard_name' => 'web']);
    $role->givePermissionTo('cheques.manage', 'finance.view');
    $this->cashier = User::factory()->create()->assignRole($role);
    $this->cashier->buildings()->attach($this->mine->id);
});

test('the finance digest goes to cheques.manage holders, counted for their buildings, and not to Vendor Support or inactive users', function () {
    $finance = matrixUser(RoleName::Finance, $this->mine); // all buildings
    $vendor = matrixUser(RoleName::VendorSupport, $this->mine);
    $inactive = matrixUser(RoleName::Finance, $this->mine);
    $inactive->forceFill(['active' => false])->save();

    expect(app(SendDigests::class)->handle('finance'))->toBe(2);

    Notification::assertSentTo($this->cashier, Digest::class, fn (Digest $d) => collect($d->items)->pluck('count', 'label')->all() === [
        'Cheques to deposit today' => 1, 'Cheques to deposit this week' => 2, 'Bounced cheques awaiting action' => 0,
    ]);
    Notification::assertSentTo($finance, Digest::class, fn (Digest $d) => $d->items[0]['count'] === 2);
    Notification::assertNotSentTo([$vendor, $inactive, User::system()], Digest::class);
});

test('nothing to report means no email (plan ruling 6)', function () {
    Cheque::query()->update(['cheque_date' => '2026-12-31']);

    expect(app(SendDigests::class)->handle('finance'))->toBe(0);
    Notification::assertNothingSent();
});

test('the documents digest counts customer and owner ID documents and never shows an ID number', function () {
    $owner = Owner::factory()->create(['id_number' => '080101234']);
    $customer = Customer::factory()->create(['id_number' => '090202345']);
    $uploader = User::factory()->create();
    foreach ([$owner, $customer] as $model) {
        (new Document)->forceFill([
            'documentable_type' => $model->getMorphClass(), 'documentable_id' => $model->id, 'category' => 'id_copy', 'expires_on' => '2026-06-30',
            'disk' => 'local', 'path' => 'documents/'.Str::uuid().'.pdf', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1, 'uploaded_by' => $uploader->id,
        ])->save();
    }
    $manager = matrixUser(RoleName::PropertyManager, $this->mine); // customers.manage, owners.view, all buildings
    $vendor = matrixUser(RoleName::VendorSupport, $this->mine);    // holds customers.manage too, but never a recipient (spec §12)
    expect($vendor->can('customers.manage'))->toBeTrue();

    app(SendDigests::class)->handle('documents');

    Notification::assertNotSentTo($vendor, Digest::class);

    Notification::assertSentTo($manager, Digest::class, function (Digest $d) use ($manager) {
        $html = (string) $d->toMail($manager)->render();

        return collect($d->items)->sum('count') === 2 && ! str_contains($html, '080101234') && ! str_contains($html, '090202345');
    });
});

test('a same-day re-run sends nobody a second email (spec §12: idempotent)', function () {
    $finance = matrixUser(RoleName::Finance, $this->mine);

    expect(app(SendDigests::class)->handle('finance'))->toBe(2)
        ->and(app(SendDigests::class)->handle('finance'))->toBe(0);
    Notification::assertSentToTimes($this->cashier, Digest::class, 1);
    Notification::assertSentToTimes($finance, Digest::class, 1);

    $this->travelTo(CarbonImmutable::parse('2026-06-09 07:00', 'Asia/Bahrain')); // the next day sends again
    expect(app(SendDigests::class)->handle('finance'))->toBe(2);
});

test('the management digest counts pending approvals and agreements expiring in 30/60/90 days', function () {
    CompanySetting::query()->update(['require_different_approver' => true]);
    app(EnsureDefaultContractTemplate::class)();
    $leasing = matrixUser(RoleName::Leasing, $this->mine);
    $draft = app(SaveAgreement::class)->handle($leasing, null, [
        'customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'frequency' => 'monthly',
        'units' => [['unit_id' => Unit::factory()->for($this->mine)->create()->id, 'charges' => [['type' => 'rent', 'monthly_amount' => '400.000', 'tax_category' => 'exempt']]]],
    ]);
    app(SubmitAgreement::class)->handle($leasing, $draft);
    foreach (['2026-06-30', '2026-07-30', '2026-08-30'] as $end) { // within 30, 60 and 90 days of 2026-06-08
        activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => $end], [Unit::factory()->for($this->mine)->create()]);
    }
    $manager = matrixUser(RoleName::Management, $this->mine); // approvals.decide

    app(SendDigests::class)->handle('management');

    Notification::assertSentTo($manager, Digest::class, fn (Digest $d) => collect($d->items)->pluck('count', 'label')->all() === [
        'Pending approvals' => 1, 'Agreements expiring in 30 days' => 1, 'Agreements expiring in 60 days' => 2, 'Agreements expiring in 90 days' => 3,
    ]);
    Notification::assertNotSentTo([$leasing, $this->cashier], Digest::class);
});

test('scheduled at 07:00 daily, and Monday 07:00 for documents', function () {
    expect(scheduledEvent('rms:digests finance')->expression)->toBe('0 7 * * *')
        ->and(scheduledEvent('rms:digests management')->expression)->toBe('0 7 * * *')
        ->and(scheduledEvent('rms:digests documents')->expression)->toBe('0 7 * * 1');
});

<?php

use App\Actions\Agreements\SaveAgreement;
use App\Actions\Agreements\SubmitAgreement;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Enums\RoleName;
use App\Livewire\Reports\IdDocumentsReport;
use App\Livewire\Reports\PendingApprovalsReport;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Owner;
use App\Models\Unit;
use App\Models\User;
use App\Reports\Queries;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create(['require_different_approver' => true]);
    app(EnsureDefaultContractTemplate::class)();
    $this->travelTo(CarbonImmutable::parse('2026-06-10 09:00', 'Asia/Bahrain'));
    [$this->mine, $this->theirs] = Building::factory()->count(2)->create()->all();
    $this->leasing = matrixUser(RoleName::Leasing, $this->mine);
    $this->otherLeasing = matrixUser(RoleName::Leasing, $this->theirs);
    $submit = function ($user, Building $building) {
        $draft = app(SaveAgreement::class)->handle($user, null, [
            'customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'frequency' => 'monthly',
            'units' => [['unit_id' => Unit::factory()->for($building)->create()->id, 'charges' => [['type' => 'rent', 'monthly_amount' => '400.000', 'tax_category' => 'exempt']]]],
        ]);

        return app(SubmitAgreement::class)->handle($user, $draft);
    };
    $this->mineApproval = $submit($this->leasing, $this->mine);
    $this->theirApproval = $submit($this->otherLeasing, $this->theirs);
});

test('pending approvals show what the viewer may view, plus their own requests', function () {
    Livewire::actingAs($this->leasing)->test(PendingApprovalsReport::class)
        ->assertSee($this->mineApproval->handler()->summary($this->mineApproval))
        ->assertDontSee($this->theirApproval->handler()->summary($this->theirApproval));
});

test('a manager may decide neither their own request nor a record they created', function () {
    $manager = matrixUser(RoleName::Management, $this->mine);
    expect(Queries::approvalsToDecide($manager)->pluck('id')->sort()->values()->all())->toBe(collect([$this->mineApproval->id, $this->theirApproval->id])->sort()->values()->all())
        ->and(Queries::approvalsToDecide($this->leasing))->toHaveCount(0); // no approvals.decide
});

test('ID documents expiring within the window, never showing the ID number', function () {
    $customer = Customer::factory()->create(['name_en' => 'Sara Ahmed', 'id_number' => '090202345']);
    $owner = Owner::factory()->create(['name_en' => 'Ali Hassan', 'id_number' => '080101234']);
    $uploader = User::factory()->create();
    $doc = fn ($model, string $category, string $expires) => tap((new Document)->forceFill([
        'documentable_type' => $model->getMorphClass(), 'documentable_id' => $model->id, 'category' => $category, 'expires_on' => $expires,
        'disk' => 'local', 'path' => 'documents/'.Str::uuid().'.pdf', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1, 'uploaded_by' => $uploader->id,
    ]))->save();
    $doc($customer, 'id_copy', '2026-06-30');
    $doc($owner, 'cr_copy', '2026-07-05');
    $doc($customer, 'id_copy', '2026-09-30'); // beyond 30 days
    $doc($customer, 'photo', '2026-06-20');   // not an ID document

    $finance = matrixUser(RoleName::Finance, $this->mine); // finance.view-all and owners.view
    Livewire::actingAs($finance)->test(IdDocumentsReport::class)
        ->assertSee('Sara Ahmed')->assertSee('Ali Hassan')->assertSee('ID copy')->assertSee('CR copy')->assertSee('30/06/2026')->assertDontSee('30/09/2026')
        ->assertDontSee('090202345')->assertDontSee('080101234');

    Livewire::actingAs($this->leasing)->test(IdDocumentsReport::class)->assertDontSee('Ali Hassan'); // no owners.view
});

test('the approvals and ID documents reports show no building filter and audit none on export', function () {
    $finance = matrixUser(RoleName::Finance, $this->mine);
    foreach ([PendingApprovalsReport::class => 'pending_approvals', IdDocumentsReport::class => 'id_documents'] as $report => $name) {
        Livewire::actingAs($finance)->test($report)->assertDontSee('All buildings')->call('export')->assertFileDownloaded();
        expect(Activity::where('event', 'report.exported')->where('properties->report', $name)->sole()->properties->has('building'))->toBeFalse();
    }
});

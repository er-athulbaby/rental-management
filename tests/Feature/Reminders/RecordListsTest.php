<?php

use App\Actions\Cheques\RecordCheques;
use App\Actions\EnsureNumberSequences;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Livewire\Reminders\RecordPanel;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\ReminderSend;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    $this->a = Building::factory()->create();
    $this->b = Building::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, $this->a);
    [$this->alpha, $this->agA] = reminderTenant(['name_en' => 'Alpha Tenant', 'email' => 'alpha@tenant.test'], '2027-01-09', $this->a);
    [$this->beta, $this->agB] = reminderTenant(['name_en' => 'Beta Tenant', 'email' => 'beta@tenant.test'], '2027-01-09', $this->b);
    $this->invA = issuedInvoice($this->alpha, [['net' => '400.000', 'au' => $this->agA->agreementUnits->first()]], '2026-11-04', $this->agA);
    $this->invB = issuedInvoice($this->beta, [['net' => '250.000', 'au' => $this->agB->agreementUnits->first()]], '2026-11-13', $this->agB);
    $this->chequeA = app(RecordCheques::class)->handle($this->finance, $this->alpha, $this->agA,
        [['cheque_no' => '501', 'bank_name' => 'NBB', 'cheque_date' => '2026-11-12', 'amount' => '400.000']])->first();
    $this->rows = [
        'invA' => logReminder($this->alpha, 'overdue', $this->invA),
        'chequeA' => logReminder($this->alpha, 'cheque', $this->chequeA, ['rule_day' => 2, 'status' => 'failed', 'reason' => 'SMTP said no']),
        'agA' => logReminder($this->alpha, 'renewal', $this->agA, ['rule_day' => 60, 'status' => 'skipped', 'reason' => 'No email']),
        'manual' => logReminder($this->alpha, 'overdue', $this->invA, ['rule_day' => null, 'manual' => true, 'sent_by' => $this->finance->id]),
        'invB' => logReminder($this->beta, 'rent_due', $this->invB, ['rule_day' => 3]),
    ];
});

test('reminders are visible as their records are: invoices and cheques need finance, agreements need agreements, in building scope', function () {
    $ids = fn ($user) => ReminderSend::query()->visibleTo($user)->orderBy('id')->pluck('id')->all();
    $r = collect($this->rows)->map(fn (ReminderSend $row) => $row->id);

    expect($ids($this->finance))->toBe($r->values()->all())
        ->and($ids(matrixUser(RoleName::Leasing, $this->a)))->toBe([$r['agA']])
        ->and($ids(matrixUser(RoleName::Leasing, $this->b)))->toBe([])
        ->and(ReminderSend::query()->inBuilding($this->a->id)->orderBy('id')->pluck('id')->all())->toBe([$r['invA'], $r['chequeA'], $r['agA'], $r['manual']])
        ->and(ReminderSend::query()->inBuilding($this->b->id)->pluck('id')->all())->toBe([$r['invB']]);
});

test('an invoice, cheque or agreement lists its own reminders: date, type, status, reason, manual or automatic', function () {
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->invA])
        ->assertSeeInOrder(['10/11/2026', 'Overdue', 'Sent', 'Manual · '.$this->finance->name, '10/11/2026', 'Overdue', 'Sent', 'Automatic'])
        ->assertDontSee('SMTP said no');
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->chequeA])
        ->assertSee('Failed')->assertSee('SMTP said no')->assertDontSee('Overdue');
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->agA])
        ->assertSee('Skipped')->assertSee('The tenant has no email address.');
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->invB])
        ->assertSee('Rent due')->assertDontSee('Overdue');
});

test('the tenant page lists the tenant\'s reminders the viewer may see, linking each record, without a send button', function () {
    Livewire::actingAs($this->finance)->test(RecordPanel::class, ['record' => $this->alpha])
        ->assertSee($this->invA->number)->assertSee('Cheque 501')->assertSee($this->agA->number)->assertDontSee($this->invB->number)
        ->assertSeeHtml(route('cheques.show', $this->chequeA))->assertDontSee('Send reminder');
    Livewire::actingAs(matrixUser(RoleName::Leasing, $this->a))->test(RecordPanel::class, ['record' => $this->alpha])
        ->assertSee($this->agA->number)->assertDontSee($this->invA->number)->assertDontSee('Cheque 501');

    $this->actingAs($this->finance)->get(route('customers.edit', $this->alpha))->assertOk()->assertSeeLivewire(RecordPanel::class);
});

test('another building\'s finance user sees only their own building\'s rows, and never a tenant record they cannot open', function () {
    $financeB = matrixUser(RoleName::Leasing, $this->b)->givePermissionTo(PermissionName::FinanceView->value); // finance.view without all-buildings access
    expect(ReminderSend::query()->visibleTo($financeB)->pluck('id')->all())->toBe([$this->rows['invB']->id]);

    Livewire::actingAs($financeB)->test(RecordPanel::class, ['record' => $this->beta])
        ->assertSee($this->invB->number)->assertDontSee($this->invA->number);
    Livewire::actingAs($financeB)->test(RecordPanel::class, ['record' => $this->invA])->assertForbidden();
});

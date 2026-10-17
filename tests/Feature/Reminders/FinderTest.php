<?php

use App\Actions\Approvals\DecideApproval;
use App\Actions\Billing\SaveCreditNote;
use App\Actions\Billing\SubmitCreditNote;
use App\Actions\Cheques\EndCheque;
use App\Actions\Cheques\RecordCheques;
use App\Actions\EnsureNumberSequences;
use App\Actions\Payments\RecordPayment;
use App\Enums\RoleName;
use App\Models\Agreement;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ReminderSetting;
use App\Models\User;
use App\Reminders\ReminderFinder;
use App\Reminders\ReminderItem;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

/** [type, item_type, item id, rule day] for each of the tenant's matches on the date, optionally of one type. */
function reminderMatches(Customer $tenant, string $date, ?string $type = null): array
{
    $items = ReminderFinder::due(CarbonImmutable::parse($date, 'Asia/Bahrain'))[$tenant->id] ?? [];

    return array_values(array_map(fn (ReminderItem $i) => [$i->type, $i->itemType(), $i->record->getKey(), $i->ruleDay],
        array_filter($items, fn (ReminderItem $i) => $type === null || $i->type === $type)));
}

beforeEach(function () {
    Storage::fake('local'); // recording a payment stores its receipt
    $this->seed(RolesAndPermissionsSeeder::class);
    fixtureBanks();
    CompanySetting::factory()->create(['default_grace_days' => 3]);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Asia/Bahrain'));
    app(EnsureNumberSequences::class)(2026);
    ReminderSetting::query()->update(['enabled' => true]);
    $this->finance = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    $this->management = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
    // Ends 09/01/2027: 60 days before is 10/11/2026, 30 days before is 10/12/2026.
    [$this->tenant, $this->agreement] = reminderTenant(['email' => 'alpha@tenant.test'], '2027-01-09');
    // Due 13/11 with the agreement's 5 grace days (grace until 18/11): rent due on 10/11, overdue from 19/11.
    $this->invoice = issuedInvoice($this->tenant, [['net' => '400.000', 'au' => $this->agreement->agreementUnits->first()]], '2026-11-13', $this->agreement);
});

test('rent due matches exactly its rule days before the due date', function (string $date, array $days) {
    expect(reminderMatches($this->tenant, $date, 'rent_due'))->toBe(array_map(fn (int $d) => ['rent_due', 'invoice', $this->invoice->id, $d], $days));
})->with([
    'the day before' => ['2026-11-09', []],
    'three days before' => ['2026-11-10', [3]],
    'the day after' => ['2026-11-11', []],
    'the due date' => ['2026-11-13', []],
]);

test('overdue counts from the end of the agreement\'s grace days', function (string $date, array $days) {
    expect(reminderMatches($this->tenant, $date, 'overdue'))->toBe(array_map(fn (int $d) => ['overdue', 'invoice', $this->invoice->id, $d], $days));
})->with([
    'last day of grace' => ['2026-11-18', []],
    'first day overdue' => ['2026-11-19', [1]],
    'second day overdue' => ['2026-11-20', []],
    'a week overdue' => ['2026-11-25', [7]],
    'two weeks overdue' => ['2026-12-02', [14]],
]);

test('an invoice without an agreement uses the company default grace days', function () {
    $manual = issuedInvoice($this->tenant, [['net' => '50.000', 'type' => 'other']], '2026-11-02'); // grace until 05/11 (3 days)

    expect(reminderMatches($this->tenant, '2026-11-05', 'overdue'))->toBe([])
        ->and(reminderMatches($this->tenant, '2026-11-06', 'overdue'))->toBe([['overdue', 'invoice', $manual->id, 1]]);
});

test('paid and fully credited invoices never match, nor do credit notes', function () {
    app(RecordPayment::class)->handle($this->finance, $this->tenant, ['received_on' => '2026-11-10', 'method' => 'cash', 'amount' => '400.000']);
    expect($this->invoice->fresh()->balance)->toBe('0.000')
        ->and(reminderMatches($this->tenant, '2026-11-10', 'rent_due'))->toBe([]);

    $other = issuedInvoice($this->tenant, [['net' => '200.000', 'au' => $this->agreement->agreementUnits->first()]], '2026-11-13', $this->agreement);
    $cn = app(SaveCreditNote::class)->handle($this->finance, $other, null, ['reason' => 'Waived', 'lines' => [['credited_line_id' => $other->lines->first()->id, 'amount' => '200.000']]]);
    app(DecideApproval::class)->handle($this->management, app(SubmitCreditNote::class)->handle($this->finance, $cn), true);

    $invoiceMatches = collect(range(0, 40))
        ->flatMap(fn (int $d) => reminderMatches($this->tenant, CarbonImmutable::parse('2026-11-01')->addDays($d)->toDateString()))
        ->filter(fn (array $m) => $m[1] === 'invoice');
    expect($other->fresh()->balance)->toBe('0.000')->and($invoiceMatches->all())->toBe([]);
});

test('a held received cheque matches its rule days before the cheque date; a cancelled one does not', function () {
    [$held, $cancelled] = app(RecordCheques::class)->handle($this->finance, $this->tenant, $this->agreement, [
        ['cheque_no' => '501', 'bank_name' => 'NBB', 'cheque_date' => '2026-11-12', 'amount' => '400.000'],
        ['cheque_no' => '502', 'bank_name' => 'NBB', 'cheque_date' => '2026-11-12', 'amount' => '400.000'],
    ])->all();
    app(EndCheque::class)->handle($this->finance, $cancelled, 'cancelled');

    expect(reminderMatches($this->tenant, '2026-11-09', 'cheque'))->toBe([])
        ->and(reminderMatches($this->tenant, '2026-11-10', 'cheque'))->toBe([['cheque', 'cheque', $held->id, 2]])
        ->and(reminderMatches($this->tenant, '2026-11-11', 'cheque'))->toBe([]);
});

test('an active agreement matches 60 and 30 days before its end, unless a renewal exists or notice was given', function () {
    expect(reminderMatches($this->tenant, '2026-11-10', 'renewal'))->toBe([['renewal', 'agreement', $this->agreement->id, 60]])
        ->and(reminderMatches($this->tenant, '2026-12-10', 'renewal'))->toBe([['renewal', 'agreement', $this->agreement->id, 30]])
        ->and(reminderMatches($this->tenant, '2026-11-11', 'renewal'))->toBe([]);

    $renewal = Agreement::factory()->create(['customer_id' => $this->tenant->id, 'previous_agreement_id' => $this->agreement->id,
        'start_date' => '2027-01-10', 'end_date' => '2028-01-09']); // a draft renewal is enough
    expect(reminderMatches($this->tenant, '2026-11-10', 'renewal'))->toBe([]);

    $renewal->delete(); // a dropped draft is soft-deleted
    expect(reminderMatches($this->tenant, '2026-11-10', 'renewal'))->toHaveCount(1);

    $this->agreement->forceFill(['notice_date' => '2026-11-01', 'planned_exit_date' => '2027-01-09'])->save();
    expect(reminderMatches($this->tenant, '2026-11-10', 'renewal'))->toBe([]);
});

test('only switched-on types match, on the days the Admin chose', function () {
    ReminderSetting::query()->where('type', 'renewal')->update(['enabled' => false]);
    ReminderSetting::query()->where('type', 'rent_due')->sole()->update(['days' => [5]]);

    expect(reminderMatches($this->tenant, '2026-11-10'))->toBe([])
        ->and(reminderMatches($this->tenant, '2026-11-08'))->toBe([['rent_due', 'invoice', $this->invoice->id, 5]]);
});

test('matches are grouped by tenant in urgency order, include tenants who will be skipped, and can be found for one tenant', function () {
    $late = issuedInvoice($this->tenant, [['net' => '100.000', 'au' => $this->agreement->agreementUnits->first()]], '2026-11-04', $this->agreement); // grace until 09/11
    [$beta] = reminderTenant(['email' => 'beta@tenant.test'], '2027-01-09');
    [$optedOut] = reminderTenant(['email' => null, 'no_reminders' => true], '2027-01-09');
    $today = CarbonImmutable::parse('2026-11-10', 'Asia/Bahrain');

    expect(reminderMatches($this->tenant, '2026-11-10'))->toBe([
        ['overdue', 'invoice', $late->id, 1], ['rent_due', 'invoice', $this->invoice->id, 3], ['renewal', 'agreement', $this->agreement->id, 60],
    ])
        ->and(array_keys(ReminderFinder::due($today)))->toEqualCanonicalizing([$this->tenant->id, $beta->id, $optedOut->id])
        ->and(array_keys(ReminderFinder::due($today, $beta->id)))->toBe([$beta->id]);
});

test('a manual reminder: rent due until grace ends, then overdue; held cheques; agreements within 90 days of their end; nothing once settled', function () {
    $on = fn (string $date) => CarbonImmutable::parse($date, 'Asia/Bahrain');

    expect(ReminderFinder::manual($this->invoice, $on('2026-11-18')))->type->toBe('rent_due')->ruleDay->toBeNull()
        ->and(ReminderFinder::manual($this->invoice, $on('2026-11-19'))->type)->toBe('overdue')
        ->and(ReminderFinder::manual($this->agreement, $on('2026-10-10')))->toBeNull()          // 91 days before the end
        ->and(ReminderFinder::manual($this->agreement, $on('2026-10-11'))->type)->toBe('renewal') // exactly 90
        ->and(ReminderFinder::manual($this->agreement, $on('2027-01-10')))->toBeNull();          // already ended

    $cheque = app(RecordCheques::class)->handle($this->finance, $this->tenant, $this->agreement,
        [['cheque_no' => '501', 'bank_name' => 'NBB', 'cheque_date' => '2026-12-01', 'amount' => '400.000']])->first();
    expect(ReminderFinder::manual($cheque, $on('2026-11-10'))->type)->toBe('cheque');

    app(EndCheque::class)->handle($this->finance, $cheque, 'cancelled');
    app(RecordPayment::class)->handle($this->finance, $this->tenant, ['received_on' => '2026-11-10', 'method' => 'cash', 'amount' => '400.000']);
    expect(ReminderFinder::manual($cheque->fresh(), $on('2026-11-10')))->toBeNull()
        ->and(ReminderFinder::manual($this->invoice->fresh(), $on('2026-11-10')))->toBeNull();
});

test('the day rolls over at midnight Asia/Bahrain, not UTC', function () {
    // 21:30 UTC on 09/11 is 00:30 on 10/11 in Bahrain: three days before the due date.
    $late = CarbonImmutable::parse('2026-11-09 21:30', 'UTC')->setTimezone('Asia/Bahrain');
    $early = CarbonImmutable::parse('2026-11-09 23:59', 'Asia/Bahrain');
    CarbonImmutable::setTestNow($late);

    expect(ReminderFinder::due(CarbonImmutable::today('Asia/Bahrain'))[$this->tenant->id][0]->ruleDay)->toBe(3);
    CarbonImmutable::setTestNow($early);
    expect(ReminderFinder::due(CarbonImmutable::today('Asia/Bahrain'))[$this->tenant->id] ?? [])->toBe([]);
    CarbonImmutable::setTestNow();
});

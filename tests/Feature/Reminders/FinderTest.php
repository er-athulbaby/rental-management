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
use App\Models\Invoice;
use App\Models\ReminderSetting;
use App\Models\User;
use App\Reminders\ReminderFinder;
use App\Reminders\ReminderItem;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
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

test('manual ignores the switch, and still refuses what fails the type\'s own rule', function () {
    ReminderSetting::query()->update(['enabled' => false]);
    $on = CarbonImmutable::parse('2026-11-10', 'Asia/Bahrain');

    expect(ReminderFinder::due($on))->toBe([])
        ->and(ReminderFinder::manual($this->invoice, $on)->type)->toBe('rent_due')
        ->and(ReminderFinder::manual($this->agreement, $on)->type)->toBe('renewal');

    $this->agreement->forceFill(['notice_date' => '2026-11-01', 'planned_exit_date' => '2027-01-09'])->save();
    expect(ReminderFinder::manual($this->agreement->fresh(), $on))->toBeNull();
});

test('manual and due use the Bahrain calendar day however the date is given', function () {
    $lastGraceDay = CarbonImmutable::parse('2026-11-18 15:00', 'Asia/Bahrain'); // a time of day, not a date
    $lateUtc = CarbonImmutable::parse('2026-11-09 21:30', 'UTC'); // 00:30 on 10/11 in Bahrain

    expect(ReminderFinder::manual($this->invoice, $lastGraceDay)->type)->toBe('rent_due')
        ->and(ReminderFinder::due($lateUtc)[$this->tenant->id][0]->ruleDay)->toBe(3);
});

/** Walks an agreement through the statuses its trigger allows (draft → pending_approval → active → ended). */
function reminderWalk(Agreement $agreement, string $to): void
{
    foreach (['draft' => [], 'pending_approval' => ['pending_approval'], 'active' => ['pending_approval', 'active'],
        'expired' => ['pending_approval', 'active', 'expired']][$to] as $step) {
        $agreement->forceFill($step === 'active' ? ['status' => 'active', 'number' => 'AGR-R-'.$agreement->id, 'verify_token' => str_repeat('a', 32)] : ['status' => $step])->save();
    }
}

test('renewals in draft, pending approval or active block a renewal reminder; sent-back, ended or soft-deleted ones do not', function (string $to, bool $blocks, bool $deleted) {
    $renewal = Agreement::factory()->create(['customer_id' => $this->tenant->id, 'previous_agreement_id' => $this->agreement->id,
        'start_date' => '2027-01-10', 'end_date' => '2028-01-09']);
    reminderWalk($renewal, $to);
    if ($to === 'pending_approval' && ! $blocks) { // the approval was rejected: back to draft, then dropped
        $renewal->forceFill(['status' => 'draft'])->save();
        $renewal->delete();
    }
    if ($deleted) {
        $renewal->delete();
    }

    expect(reminderMatches($this->tenant, '2026-11-10', 'renewal'))->toHaveCount($blocks ? 0 : 1);
})->with([
    'draft' => ['draft', true, false],
    'pending approval' => ['pending_approval', true, false],
    'active' => ['active', true, false],
    'expired' => ['expired', false, false],
    'a rejected one, dropped' => ['pending_approval', false, false],
    'a deleted draft' => ['draft', false, true],
]);

test('renewals are not matched the day before or after a rule day', function (string $date) {
    expect(reminderMatches($this->tenant, $date, 'renewal'))->toBe([]);
})->with(['59 days' => '2026-11-11', '61 days' => '2026-11-09', '29 days' => '2026-12-11', '31 days' => '2026-12-09']);

test('only active agreements are matched', function (string $to) {
    $this->agreement->forceFill(['status' => 'expired'])->save();
    if ($to !== 'expired') {
        $this->agreement->forceFill(['status' => $to])->save();
    }

    expect(reminderMatches($this->tenant, '2026-11-10', 'renewal'))->toBe([]);
})->with(['expired', 'closed', 'terminated', 'renewed']);

test('a deleted tenant drops out of every type', function () {
    $cheque = app(RecordCheques::class)->handle($this->finance, $this->tenant, $this->agreement,
        [['cheque_no' => '501', 'bank_name' => 'NBB', 'cheque_date' => '2026-11-12', 'amount' => '400.000']])->first();
    expect(reminderMatches($this->tenant, '2026-11-10'))->toHaveCount(3);

    $this->tenant->delete(); // an active agreement itself cannot be soft-deleted: its trigger freezes it
    expect(reminderMatches($this->tenant, '2026-11-10'))->toBe([])
        ->and(ReminderFinder::manual($cheque, CarbonImmutable::parse('2026-11-10', 'Asia/Bahrain')))->toBeNull()
        ->and(ReminderFinder::manual($this->agreement, CarbonImmutable::parse('2026-11-10', 'Asia/Bahrain')))->toBeNull();
});

test('only held received cheques match: not deposited, cleared, bounced or issued ones', function (string $to) {
    $cheque = app(RecordCheques::class)->handle($this->finance, $this->tenant, $this->agreement,
        [['cheque_no' => '501', 'bank_name' => 'NBB', 'cheque_date' => '2026-11-12', 'amount' => '400.000']])->first();
    expect(reminderMatches($this->tenant, '2026-11-10', 'cheque'))->toHaveCount(1);

    foreach (['deposited' => ['deposited'], 'bounced' => ['deposited', 'bounced']][$to] as $step) {
        DB::table('cheques')->where('id', $cheque->id)->update(['status' => $step]);
    }

    expect(reminderMatches($this->tenant, '2026-11-10', 'cheque'))->toBe([]);
})->with(['deposited', 'bounced']);

test('a cheque we issued is not matched, even on the date', function () {
    DB::table('cheques')->insert(['direction' => 'issued', 'customer_id' => $this->tenant->id, 'cheque_no' => '9', 'bank_name' => 'NBB',
        'cheque_date' => '2026-11-12', 'amount' => '1.000', 'status' => 'issued', 'created_by' => $this->finance->id, 'created_at' => now(), 'updated_at' => now()]);

    expect(reminderMatches($this->tenant, '2026-11-10', 'cheque'))->toBe([]);
});

test('only issued invoices with a balance match: not draft or cancelled ones; part-paid ones do; a credit note never has a balance', function () {
    $draft = fn (array $attributes = []) => (new Invoice)->forceFill([
        'type' => 'manual', 'customer_id' => $this->tenant->id, 'issue_date' => '2026-11-13', 'due_date' => '2026-11-13', 'status' => 'draft',
        'subtotal' => '10.000', 'tax_total' => '0.000', 'total' => '10.000', ...$attributes,
    ]);
    $draftInvoice = $draft();
    $draftInvoice->save();
    $cancelled = $draft();
    $cancelled->save();
    $cancelled->forceFill(['status' => 'cancelled'])->save();
    $note = $draft(['type' => 'credit_note']);
    $note->save();

    expect($draftInvoice->fresh()->balance)->toBe('10.000')->and($cancelled->fresh()->balance)->toBe('10.000')
        ->and($note->fresh()->balance)->toBe('0.000') // the schema zeroes a credit note's balance, so `type != credit_note` is defence in depth
        ->and(reminderMatches($this->tenant, '2026-11-10', 'rent_due'))->toBe([['rent_due', 'invoice', $this->invoice->id, 3]]);

    app(RecordPayment::class)->handle($this->finance, $this->tenant, ['received_on' => '2026-11-10', 'method' => 'cash', 'amount' => '100.000']);
    expect($this->invoice->fresh()->balance)->toBe('300.000')
        ->and(reminderMatches($this->tenant, '2026-11-10', 'rent_due'))->toBe([['rent_due', 'invoice', $this->invoice->id, 3]]);
});

test('a tenant with no email and an opted-out tenant are each still matched, for the sender to skip', function (array $attributes) {
    [$tenant] = reminderTenant($attributes, '2027-01-09');

    expect(reminderMatches($tenant, '2026-11-10', 'renewal'))->toHaveCount(1);
})->with([
    'no email' => [['email' => null]],
    'opted out' => [['email' => 'out@tenant.test', 'no_reminders' => true]],
]);

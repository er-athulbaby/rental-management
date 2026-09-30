<?php

use App\Actions\Agreements\SubmitAgreement;
use App\Actions\ContractTemplates\EnsureDefaultContractTemplate;
use App\Enums\RoleName;
use App\Models\Agreement;
use App\Models\CompanySetting;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    app(EnsureDefaultContractTemplate::class)();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Bahrain'));
    $this->pm = User::factory()->withTwoFactor()->create()->assignRole(RoleName::PropertyManager);
    $this->unit = Unit::factory()->create(['code' => 'U-7']);
});

function draftFor(Unit $unit, string $from, string $to, User $creator): Agreement
{
    $agreement = Agreement::factory()->create(['start_date' => $from, 'end_date' => $to, 'created_by' => $creator->id]);
    $au = $agreement->agreementUnits()->create(['unit_id' => $unit->id, 'list_rent' => '400.000', 'deposit_amount' => 0, 'start_date' => $from, 'end_date' => $to]);
    $au->charges()->create(['type' => 'rent', 'monthly_amount' => '400.000', 'tax_category' => 'exempt']);

    return $agreement;
}

test('an occupied unit cannot be let for overlapping dates, but can be pre-let from the day after', function () {
    activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-10-31'], [$this->unit]);

    expect(fn () => app(SubmitAgreement::class)->handle($this->pm, draftFor($this->unit, '2026-10-31', '2027-10-30', $this->pm)))
        ->toThrow(ValidationException::class, 'U-7');

    app(SubmitAgreement::class)->handle($this->pm, draftFor($this->unit, '2026-11-01', '2027-10-31', $this->pm));
    expect(Agreement::where('status', 'pending_approval')->count())->toBe(1);
});

test('a blocked unit is rejected', function () {
    $this->unit->update(['blocked' => true, 'blocked_reason' => 'Renovation']);

    expect(fn () => app(SubmitAgreement::class)->handle($this->pm, draftFor($this->unit, '2026-11-01', '2027-10-31', $this->pm)))
        ->toThrow(ValidationException::class, 'blocked');
});

test('drafts do not hold units: the second to submit is rejected', function () {
    $first = draftFor($this->unit, '2026-11-01', '2027-10-31', $this->pm);
    $second = draftFor($this->unit, '2027-01-01', '2027-12-31', $this->pm);

    app(SubmitAgreement::class)->handle($this->pm, $first);
    expect(fn () => app(SubmitAgreement::class)->handle($this->pm, $second))->toThrow(ValidationException::class);
});

test('an expired agreement without a move-out is an open-ended overstay', function () {
    $old = activeAgreement(['start_date' => '2025-01-01', 'end_date' => '2025-12-31'], [$this->unit]);
    $old->forceFill(['status' => 'expired'])->save();

    expect(fn () => app(SubmitAgreement::class)->handle($this->pm, draftFor($this->unit, '2027-06-01', '2028-05-31', $this->pm)))
        ->toThrow(ValidationException::class);
});

test('a move-out after the end date extends occupancy to the move-out', function () {
    $current = activeAgreement(['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$this->unit]);
    DB::table('agreement_units')->where('agreement_id', $current->id)->update(['move_out_date' => '2027-01-15']);

    expect(fn () => app(SubmitAgreement::class)->handle($this->pm, draftFor($this->unit, '2027-01-10', '2027-12-31', $this->pm)))
        ->toThrow(ValidationException::class);
    app(SubmitAgreement::class)->handle($this->pm, draftFor($this->unit, '2027-01-16', '2027-12-31', $this->pm));
});

test('submit refuses when the unit lines changed after the overlap check locked them', function () {
    $draft = draftFor($this->unit, '2026-11-01', '2027-10-31', $this->pm);
    $other = Unit::factory()->create();
    $done = false;
    // Simulates a save landing between the unit locks and the agreement lock: a new line appears when the agreement row is locked.
    DB::listen(function ($query) use (&$done, $draft, $other) {
        if (! $done && str_contains($query->sql, 'from `agreements`') && str_contains($query->sql, 'for update')) {
            $done = true;
            $draft->agreementUnits()->create(['unit_id' => $other->id, 'list_rent' => '1.000', 'deposit_amount' => 0, 'start_date' => $draft->start_date, 'end_date' => $draft->end_date]);
        }
    });
    expect(fn () => app(SubmitAgreement::class)->handle($this->pm, $draft))
        ->toThrow(ValidationException::class, 'changed while submitting');
    expect($draft->fresh()->status->value)->toBe('draft');
});

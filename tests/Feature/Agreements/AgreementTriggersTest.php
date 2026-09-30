<?php

use App\Models\Agreement;
use App\Models\Unit;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// Spec §8.5 — enforced by MySQL, not only by the Actions.

beforeEach(function () {
    $this->draft = Agreement::factory()->create();
    $this->unit = Unit::factory()->create();
    $this->au = $this->draft->agreementUnits()->create([
        'unit_id' => $this->unit->id, 'list_rent' => '400.000', 'deposit_amount' => '400.000',
        'start_date' => $this->draft->start_date, 'end_date' => $this->draft->end_date,
    ]);
    $this->charge = $this->au->charges()->create(['type' => 'rent', 'monthly_amount' => '400.000', 'tax_category' => 'exempt']);
    $this->draft->clauses()->create(['position' => 1, 'heading_en' => 'A', 'heading_ar' => 'أ', 'body_en' => 'x', 'body_ar' => 'س']);
});

function agRow(Agreement $a): Builder
{
    return DB::table('agreements')->where('id', $a->id);
}

function submitAndActivate(Agreement $a): void
{
    agRow($a)->update(['status' => 'pending_approval']);
    agRow($a)->update(['status' => 'active', 'number' => 'AGR-2026-000001', 'verify_token' => str_repeat('a', 32)]);
}

test('agreements are never hard-deleted, and only drafts are soft-deleted', function () {
    expect(fn () => agRow($this->draft)->delete())->toThrow(QueryException::class, 'agreements cannot be deleted');

    submitAndActivate($this->draft);
    expect(fn () => agRow($this->draft)->update(['deleted_at' => now()]))->toThrow(QueryException::class, 'terms are frozen');
});

test('status moves only along the spec diagram', function () {
    agRow($this->draft)->update(['status' => 'pending_approval']);
    agRow($this->draft)->update(['status' => 'draft']);
    expect(fn () => agRow($this->draft)->update(['status' => 'active', 'number' => 'AGR-X', 'verify_token' => str_repeat('b', 32)]))
        ->toThrow(QueryException::class, 'status change not allowed');

    submitAndActivate($this->draft);
    expect(fn () => agRow($this->draft)->update(['status' => 'draft']))->toThrow(QueryException::class, 'status change not allowed');

    agRow($this->draft)->update(['status' => 'expired']);
    expect(fn () => agRow($this->draft)->update(['status' => 'active']))->toThrow(QueryException::class, 'status change not allowed');
    agRow($this->draft)->update(['status' => 'closed']);
});

test('a pending agreement and its units, charges and clauses are locked', function () {
    agRow($this->draft)->update(['status' => 'pending_approval']);

    expect(fn () => agRow($this->draft)->update(['start_date' => '2020-01-01']))->toThrow(QueryException::class, 'terms are frozen');
    expect(fn () => agRow($this->draft)->update(['planned_exit_date' => '2027-01-01']))->toThrow(QueryException::class, 'only notice dates');
    expect(fn () => DB::table('agreement_units')->where('id', $this->au->id)->update(['deposit_amount' => '1.000']))->toThrow(QueryException::class, 'agreement_units');
    expect(fn () => DB::table('agreement_units')->where('id', $this->au->id)->update(['planned_exit_date' => '2027-01-01']))->toThrow(QueryException::class, 'locked while pending');
    expect(fn () => DB::table('agreement_unit_charges')->where('id', $this->charge->id)->update(['monthly_amount' => '1.000']))->toThrow(QueryException::class, 'agreement_unit_charges');
    expect(fn () => DB::table('agreement_units')->insert(['agreement_id' => $this->draft->id, 'unit_id' => Unit::factory()->create()->id, 'list_rent' => 1, 'deposit_amount' => 1, 'start_date' => '2026-11-01', 'end_date' => '2026-12-01']))
        ->toThrow(QueryException::class, 'agreement_units');
    expect(fn () => DB::table('agreement_clauses')->where('agreement_id', $this->draft->id)->update(['body_en' => 'changed']))->toThrow(QueryException::class, 'agreement_clauses');
    expect(fn () => DB::table('agreement_clauses')->where('agreement_id', $this->draft->id)->delete())->toThrow(QueryException::class, 'agreement_clauses');
});

test('an active agreement records notice and may only shorten its end date', function () {
    submitAndActivate($this->draft);
    $end = agRow($this->draft)->value('end_date');

    agRow($this->draft)->update(['notice_date' => '2027-01-15', 'planned_exit_date' => '2027-03-31']);
    DB::table('agreement_units')->where('id', $this->au->id)->update(['planned_exit_date' => '2027-03-31']);

    expect(fn () => agRow($this->draft)->update(['end_date' => now()->parse($end)->addYear()->toDateString()]))->toThrow(QueryException::class, 'only notice dates');
    expect(fn () => DB::table('agreement_units')->where('id', $this->au->id)->update(['end_date' => now()->parse($end)->addDay()->toDateString()]))->toThrow(QueryException::class, 'agreement_units');
    expect(fn () => DB::table('agreement_units')->where('id', $this->au->id)->delete())->toThrow(QueryException::class, 'agreement_units');
});

test('one rent charge per agreement unit is a database rule', function () {
    expect(fn () => $this->au->charges()->create(['type' => 'rent', 'monthly_amount' => '1.000', 'tax_category' => 'exempt']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a number and verify token exist exactly when the agreement is past approval', function () {
    agRow($this->draft)->update(['status' => 'pending_approval']);
    expect(fn () => agRow($this->draft)->update(['status' => 'active']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(3819));
});

test('rows cannot be re-parented into a submitted agreement', function () {
    $active = activeAgreement([], [Unit::factory()->create()]);
    $activeAu = DB::table('agreement_units')->where('agreement_id', $active->id)->first();

    expect(fn () => DB::table('agreement_units')->where('id', $this->au->id)->update(['agreement_id' => $active->id]))
        ->toThrow(QueryException::class, 'cannot move');
    expect(fn () => DB::table('agreement_unit_charges')->where('id', $this->charge->id)->update(['agreement_unit_id' => $activeAu->id]))
        ->toThrow(QueryException::class, 'cannot move');
    expect(fn () => DB::table('agreement_clauses')->where('agreement_id', $this->draft->id)->update(['agreement_id' => $active->id]))
        ->toThrow(QueryException::class, 'cannot move');
});

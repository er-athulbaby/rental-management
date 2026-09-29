<?php

use App\Models\OwnerContract;
use App\Models\Unit;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Spec §8.5 — each rule is enforced by MySQL, not only by the Actions.

beforeEach(function () {
    $this->draft = OwnerContract::factory()->create();
    $this->unit = Unit::factory()->create(['building_id' => $this->draft->building_id]);
    $this->spare = Unit::factory()->create(['building_id' => $this->draft->building_id]);
    $this->draft->units()->attach($this->unit->id);
});

function ocRow(OwnerContract $contract): Builder
{
    return DB::table('owner_contracts')->where('id', $contract->id);
}

test('owner contracts can never be deleted, not even drafts', function () {
    expect(fn () => ocRow($this->draft)->delete())->toThrow(QueryException::class, 'owner_contracts cannot be deleted');
});

test('drafts are freely editable, including their units', function () {
    ocRow($this->draft)->update(['fee_value' => '9.000']);
    $this->draft->units()->attach($this->spare->id);
    $this->draft->units()->detach($this->unit->id);

    expect($this->draft->units()->pluck('units.id')->all())->toBe([$this->spare->id]);
});

test('status only moves forward, except a rejection back to draft', function () {
    ocRow($this->draft)->update(['status' => 'pending_approval']);
    ocRow($this->draft)->update(['status' => 'draft']);                          // rejected
    ocRow($this->draft)->update(['status' => 'pending_approval']);
    ocRow($this->draft)->update(['status' => 'active', 'number' => 'OC-2026-000001']);

    expect(fn () => ocRow($this->draft)->update(['status' => 'draft']))->toThrow(QueryException::class, 'status change not allowed');
    expect(fn () => ocRow($this->draft)->update(['status' => 'pending_approval']))->toThrow(QueryException::class, 'status change not allowed');

    ocRow($this->draft)->update(['status' => 'ended']);
    expect(fn () => ocRow($this->draft)->update(['status' => 'active']))->toThrow(QueryException::class, 'status change not allowed');
});

test('a draft cannot jump straight to active', function () {
    expect(fn () => ocRow($this->draft)->update(['status' => 'active', 'number' => 'OC-2026-000009']))->toThrow(QueryException::class, 'status change not allowed');
});

test('terms are frozen once submitted', function () {
    ocRow($this->draft)->update(['status' => 'pending_approval']);

    expect(fn () => ocRow($this->draft)->update(['fee_value' => '9.000']))->toThrow(QueryException::class, 'terms are frozen');
    expect(fn () => ocRow($this->draft)->update(['end_date' => '2030-01-01']))->toThrow(QueryException::class, 'only end_date');
});

test('an active contract may only shorten its end date and set its termination once', function () {
    ocRow($this->draft)->update(['status' => 'pending_approval']);
    ocRow($this->draft)->update(['status' => 'active', 'number' => 'OC-2026-000001']);
    $end = ocRow($this->draft)->value('end_date');

    expect(fn () => ocRow($this->draft)->update(['end_date' => '2099-01-01']))->toThrow(QueryException::class, 'only end_date');
    expect(fn () => ocRow($this->draft)->update(['number' => 'OC-2026-000002']))->toThrow(QueryException::class, 'terms are frozen');

    $shorter = now()->parse($end)->subMonth()->toDateString();
    ocRow($this->draft)->update(['end_date' => $shorter, 'terminated_on' => $shorter, 'termination_reason' => 'Sold']);

    expect(fn () => ocRow($this->draft)->update(['terminated_on' => now()->parse($shorter)->subDay()->toDateString()]))->toThrow(QueryException::class, 'only end_date');
});

test('contract units are frozen once the contract is not draft', function () {
    ocRow($this->draft)->update(['status' => 'pending_approval']);

    expect(fn () => $this->draft->units()->attach($this->spare->id))->toThrow(QueryException::class, 'owner_contract_units are frozen');
    expect(fn () => $this->draft->units()->detach($this->unit->id))->toThrow(QueryException::class, 'owner_contract_units are frozen');
    expect(fn () => DB::table('owner_contract_units')->update(['unit_id' => $this->spare->id]))->toThrow(QueryException::class, 'owner_contract_units cannot be updated');
});

test('type-specific terms are enforced by CHECK constraints', function () {
    expect(fn () => ocRow($this->draft)->update(['rent_amount' => '100.000']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(3819));
});

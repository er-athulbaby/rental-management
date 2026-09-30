<?php

use App\Models\Customer;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Spec §8.5, enforced by MySQL.

beforeEach(function () {
    $this->invoiceId = DB::table('invoices')->insertGetId([
        'type' => 'manual', 'customer_id' => Customer::factory()->create()->id, 'issue_date' => '2026-10-05', 'due_date' => '2026-10-05',
        'status' => 'draft', 'subtotal' => '100.000', 'tax_total' => '0.000', 'total' => '100.000', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->lineId = DB::table('invoice_lines')->insertGetId([
        'invoice_id' => $this->invoiceId, 'charge_type' => 'other', 'description' => 'x', 'net' => '100.000',
        'tax_category' => 'exempt', 'tax_rate' => '0.00', 'tax_amount' => '0.000', 'total' => '100.000', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

function inv(int $id): Builder
{
    return DB::table('invoices')->where('id', $id);
}

function issueRaw(int $id): void
{
    inv($id)->update(['status' => 'issued', 'number' => 'INV-2026-999999', 'grace_until' => '2026-10-10', 'issued_at' => now()]);
}

test('invoices are never deleted', function () {
    expect(fn () => inv($this->invoiceId)->delete())->toThrow(QueryException::class, 'invoices cannot be deleted');
});

test('lines are added only to drafts; a scheduled line only takes tax and its owner stamp', function () {
    inv($this->invoiceId)->update(['status' => 'scheduled']);

    expect(fn () => DB::table('invoice_lines')->insert(['invoice_id' => $this->invoiceId, 'charge_type' => 'other', 'description' => 'y', 'net' => 1, 'tax_category' => 'exempt', 'tax_rate' => 0, 'tax_amount' => 0, 'total' => 1]))
        ->toThrow(QueryException::class, 'invoice_lines');
    expect(fn () => DB::table('invoice_lines')->where('id', $this->lineId)->update(['net' => '90.000', 'total' => '90.000']))
        ->toThrow(QueryException::class, 'invoice_lines');

    DB::table('invoice_lines')->where('id', $this->lineId)->update(['tax_rate' => '10.00', 'tax_amount' => '10.000', 'total' => '110.000']);
    expect(fn () => DB::table('invoice_lines')->where('id', $this->lineId)->delete())->toThrow(QueryException::class, 'invoice_lines');
});

test('an issued invoice changes only its allocated and credited amounts', function () {
    issueRaw($this->invoiceId);

    expect(fn () => inv($this->invoiceId)->update(['total' => '1.000', 'subtotal' => '1.000']))->toThrow(QueryException::class, 'issued invoice is immutable');
    expect(fn () => inv($this->invoiceId)->update(['status' => 'cancelled']))->toThrow(QueryException::class, 'status change not allowed');
    expect(fn () => DB::table('invoice_lines')->where('id', $this->lineId)->update(['net' => '1.000', 'total' => '1.000']))->toThrow(QueryException::class, 'invoice_lines');

    DB::table('invoice_lines')->where('id', $this->lineId)->update(['allocated' => '40.000']);
    inv($this->invoiceId)->update(['allocated' => '40.000']);
    expect(inv($this->invoiceId)->value('balance'))->toBe('60.000');
});

test('allocations can never exceed the total', function () {
    issueRaw($this->invoiceId);

    expect(fn () => DB::table('invoice_lines')->where('id', $this->lineId)->update(['allocated' => '100.001']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(3819));
});

test('a number appears exactly when the invoice is issued', function () {
    expect(fn () => inv($this->invoiceId)->update(['number' => 'INV-2026-000001']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(3819));
});

test('a line cannot be moved to another invoice', function () {
    $other = DB::table('invoices')->insertGetId([
        'type' => 'manual', 'customer_id' => Customer::factory()->create()->id, 'issue_date' => '2026-10-05', 'due_date' => '2026-10-05',
        'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('invoice_lines')->where('id', $this->lineId)->update(['invoice_id' => $other]))
        ->toThrow(QueryException::class, 'cannot move to another invoice');
});

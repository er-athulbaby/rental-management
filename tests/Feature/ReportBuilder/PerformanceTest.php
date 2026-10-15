<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Unit;
use App\Reports\Builder\Engine;
use App\Reports\Builder\ReportDefinition;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

test('10,000 invoices grouped by building and month run in under 2 seconds', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $building = Building::factory()->create();
    $management = matrixUser(RoleName::Management, $building);
    $agreement = activeAgreement(['customer_id' => Customer::factory()->create()->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'], [$unit = Unit::factory()->for($building)->create()]);
    $rows = [];
    for ($i = 0; $i < 10000; $i++) {
        $rows[] = ['type' => 'manual', 'customer_id' => $agreement->customer_id, 'agreement_id' => $agreement->id, 'issue_date' => '2026-01-01',
            'due_date' => '2026-'.str_pad((string) (1 + $i % 12), 2, '0', STR_PAD_LEFT).'-01', 'status' => 'draft',
            'subtotal' => '10.000', 'tax_total' => '0.000', 'total' => '10.000', 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 1000) as $chunk) {
        DB::table('invoices')->insert($chunk);
    }
    // One line per invoice, so the building grouping goes through invoice_lines -> units like real data.
    $lines = DB::table('invoices')->pluck('id')->map(fn ($id) => ['invoice_id' => $id, 'unit_id' => $unit->id, 'charge_type' => 'other',
        'description' => 'Perf', 'net' => '10.000', 'tax_category' => 'standard', 'tax_rate' => '0.00', 'tax_amount' => '0.000',
        'total' => '10.000', 'created_at' => now(), 'updated_at' => now()])->all();
    foreach (array_chunk($lines, 1000) as $chunk) {
        DB::table('invoice_lines')->insert($chunk);
    }

    $start = microtime(true);
    $r = app(Engine::class)->run($management, ReportDefinition::fromArray(['subject' => 'invoices', 'columns' => ['number', 'total'],
        'groups' => [['field' => 'building'], ['field' => 'due_date', 'bucket' => 'month']], 'totals' => ['total' => 'sum'], 'summary_only' => true], $management));
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(2.0)->and($r->grandTotals['total:sum'])->toBe('100000.000');
});

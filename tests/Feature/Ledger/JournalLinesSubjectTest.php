<?php

use App\Models\SavedReport;
use App\Reports\Builder\Registry;
use App\Reports\Packages\PackageRunner;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
});

test('the Journal lines subject reports the ledger to ledger.view holders, with dimensions and totals', function () {
    $f = $this->f;
    $ownerPayable = runReport($f['finance'], ['subject' => 'journal_lines', 'columns' => ['date', 'account_code', 'owner', 'debit', 'credit'],
        'filters' => [['field' => 'account_code', 'op' => 'is', 'value' => '2100']], 'totals' => ['debit' => 'sum', 'credit' => 'sum']]);
    $tower = runReport($f['admin'], ['subject' => 'journal_lines', 'columns' => ['account_code', 'debit', 'credit'], 'building_id' => $f['tower']->id,
        'filters' => [['field' => 'source', 'op' => 'in', 'value' => ['expense']]], 'totals' => ['debit' => 'sum']]);

    expect($ownerPayable->rowCount)->toBe(5)
        ->and($ownerPayable->grandTotals['debit:sum'])->toBe('352.000')
        ->and($ownerPayable->grandTotals['credit:sum'])->toBe('700.000')
        ->and($tower->rowCount)->toBe(4)
        ->and($tower->grandTotals['debit:sum'])->toBe('80.000')
        ->and(array_keys(Registry::forUser($f['finance'])))->toContain('journal_lines')
        ->and(array_keys(Registry::forUser($f['management'])))->not->toContain('journal_lines');
});

test('a user without ledger.view cannot run the Journal lines subject', function () {
    runReport($this->f['management'], ['subject' => 'journal_lines', 'columns' => ['date', 'debit']]);
})->throws(ValidationException::class, 'You cannot report on this subject.');

test('a package runs a saved Journal lines report for ledger.view and skips it for everyone else', function () {
    $f = $this->f;
    $saved = SavedReport::create(['name' => 'Ledger lines', 'subject' => 'journal_lines', 'visibility' => 'shared', 'created_by' => $f['finance']->id, 'updated_by' => $f['finance']->id,
        'definition' => ['version' => 1, 'subject' => 'journal_lines', 'columns' => ['account_code', 'debit'], 'totals' => ['debit' => 'sum'], 'sort' => ['field' => 'account_code', 'dir' => 'asc']]]);
    $package = packageWith($f['finance'], [['kind' => 'saved', 'saved_report_id' => $saved->id]]);
    $run = fn ($user) => app(PackageRunner::class)->run($package, $user, CarbonImmutable::now('Asia/Bahrain'));

    expect($run($f['finance'])->sheets[0]->skipped)->toBeNull()
        ->and($run($f['management'])->sheets[0]->skipped)->toBe('You do not have access to this report.')
        ->and($f['management']->can('view', $saved))->toBeFalse();
});

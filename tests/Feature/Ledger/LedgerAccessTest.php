<?php

use App\Enums\RoleName;
use App\Models\Journal;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->f = ledgerFixture();
});

test('only Finance and Admin reach the ledger pages, each to its own', function () {
    $f = $this->f;
    $journal = Journal::query()->orderBy('id')->firstOrFail();
    $pages = ['ledger.index' => [], 'ledger.trial-balance' => [], 'ledger.balance-sheet' => [], 'ledger.profit-and-loss' => [], 'ledger.general-ledger' => [],
        'ledger.journals' => [], 'ledger.journals.show' => ['journal' => $journal->id], 'ledger.journals.create' => [], 'ledger.accounts' => [], 'ledger.setup' => [],
        'ledger.cash-movements' => [], 'ledger.vat-returns' => []];
    $adminOnly = ['ledger.accounts', 'ledger.setup'];
    $financeOnly = ['ledger.journals.create'];
    $leasing = matrixUser(RoleName::Leasing, $f['tower']);
    $vendorSupport = matrixUser(RoleName::VendorSupport, $f['tower']);

    foreach ($pages as $name => $parameters) {
        $this->actingAs($f['management'])->get(route($name, $parameters))->assertForbidden();
        $this->actingAs($leasing)->get(route($name, $parameters))->assertForbidden();
        $this->actingAs($vendorSupport)->get(route($name, $parameters))->assertForbidden();
        $this->actingAs($f['finance'])->get(route($name, $parameters))->assertStatus(in_array($name, $adminOnly, true) ? 403 : 200);
        $this->actingAs($f['admin'])->get(route($name, $parameters))->assertStatus(in_array($name, $financeOnly, true) ? 403 : 200);
    }
    $this->actingAs($f['finance'])->get('/ledger/journals/abc')->assertNotFound();
});

test('every string the ledger shows has one Arabic translation, and ar.json has no duplicate keys', function () {
    $json = (string) file_get_contents(lang_path('ar.json'));
    $ar = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    preg_match_all('/^\s*"((?:[^"\\\\]|\\\\.)*)"\s*:/m', $json, $keys);
    $files = [
        ...glob(app_path('Ledger/*.php')), ...glob(app_path('Actions/Ledger/*.php')), ...glob(app_path('Livewire/Ledger/*.php')),
        ...glob(resource_path('views/livewire/ledger/*.blade.php')),
        app_path('Enums/LedgerAccountType.php'), app_path('Enums/CashMovementKind.php'), app_path('Reports/Builder/Subjects/JournalLines.php'),
        app_path('Livewire/Reports/TrialBalanceReport.php'), app_path('Livewire/Reports/BalanceSheetReport.php'), app_path('Livewire/Reports/ProfitAndLossReport.php'),
        app_path('Livewire/Reports/GeneralLedgerReport.php'), app_path('Livewire/Reports/JournalListReport.php'),
    ];
    $missing = [];
    foreach ($files as $file) {
        preg_match_all('/__\(\s*([\'"])((?:(?!\1)[^\\\\]|\\\\.)*)\1/', (string) file_get_contents($file), $m);
        foreach ($m[2] as $key) {
            $key = stripslashes($key);
            if (! array_key_exists($key, $ar)) {
                $missing[] = basename($file).': '.$key;
            }
        }
    }

    expect($missing)->toBe([])
        ->and(count($keys[1]))->toBe(count(array_unique($keys[1])));
});

test('the vendor bill strings have Arabic', function () {
    $ar = json_decode((string) file_get_contents(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
    $keys = ['Vendor bill for :vendor: :list', 'Choose the vendor.', 'Tick at least one expense.', 'Choose expenses from the list.', 'Expense :description is reversed.',
        'Expense :description is already on payment out :n.', 'This expense is on payment out :n. Reject or reverse that payment out first.',
        'The payment cannot be dated before its latest expense (:d).', 'Vendor', 'vendor', "Unpaid expenses: this vendor's work orders first; add any other unpaid expense below.",
        'No unpaid expenses.', 'Add another unpaid expense', 'Add', 'Choose an unpaid expense.', 'Total: :t BHD', 'In payment', 'Money account',
        'Only an asset account can be a money account.', 'Bank charges', 'Bank interest income'];

    expect(array_values(array_diff($keys, array_keys($ar))))->toBe([]);
});

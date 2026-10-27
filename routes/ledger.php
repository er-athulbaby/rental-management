<?php

use App\Livewire\Ledger;
use App\Livewire\Reports;
use Illuminate\Support\Facades\Route;

// General ledger (spec 2026-10-24, plan ruling 12). Each page re-checks its permission in mount(); the middleware answers 403 first.
Route::middleware(['auth:web'])->prefix('ledger')->name('ledger.')->group(function () {
    Route::livewire('/', Ledger\Home::class)->middleware('can:ledger.view')->name('index');
    Route::livewire('accounts', Ledger\Accounts::class)->middleware('can:ledger.admin')->name('accounts');
    Route::livewire('setup', Ledger\Setup::class)->middleware('can:ledger.admin')->name('setup');
    Route::livewire('journals/new', Ledger\NewJournal::class)->middleware('can:ledger.manage')->name('journals.create');
    Route::livewire('journals/{journal}', Ledger\JournalShow::class)->middleware('can:ledger.view')->whereNumber('journal')->name('journals.show');
    Route::livewire('trial-balance', Reports\TrialBalanceReport::class)->middleware('can:ledger.view')->name('trial-balance');
    Route::livewire('balance-sheet', Reports\BalanceSheetReport::class)->middleware('can:ledger.view')->name('balance-sheet');
    Route::livewire('profit-and-loss', Reports\ProfitAndLossReport::class)->middleware('can:ledger.view')->name('profit-and-loss');
    Route::livewire('general-ledger', Reports\GeneralLedgerReport::class)->middleware('can:ledger.view')->name('general-ledger');
    Route::livewire('journals', Reports\JournalListReport::class)->middleware('can:ledger.view')->name('journals');
});

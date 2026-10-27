<?php

use App\Livewire\Ledger;
use Illuminate\Support\Facades\Route;

// General ledger (spec 2026-10-24, plan ruling 12). Each page re-checks its permission in mount(); the middleware answers 403 first.
Route::middleware(['auth:web'])->prefix('ledger')->name('ledger.')->group(function () {
    Route::livewire('/', Ledger\Home::class)->middleware('can:ledger.view')->name('index');
    Route::livewire('accounts', Ledger\Accounts::class)->middleware('can:ledger.admin')->name('accounts');
    Route::livewire('setup', Ledger\Setup::class)->middleware('can:ledger.admin')->name('setup');
    Route::livewire('journals/new', Ledger\NewJournal::class)->middleware('can:ledger.manage')->name('journals.create');
    Route::livewire('journals/{journal}', Ledger\JournalShow::class)->middleware('can:ledger.view')->whereNumber('journal')->name('journals.show');
});

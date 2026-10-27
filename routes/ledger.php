<?php

use App\Livewire\Ledger;
use Illuminate\Support\Facades\Route;

// General ledger (spec 2026-10-24, plan ruling 12). Each page re-checks its permission in mount(); the middleware answers 403 first.
Route::middleware(['auth:web'])->prefix('ledger')->name('ledger.')->group(function () {
    Route::livewire('accounts', Ledger\Accounts::class)->middleware('can:ledger.admin')->name('accounts');
    Route::livewire('setup', Ledger\Setup::class)->middleware('can:ledger.admin')->name('setup');
});

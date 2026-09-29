<?php

use App\Livewire\Admin\CompanySettings;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('settings', CompanySettings::class)->middleware('can:settings.manage')->name('settings');
});

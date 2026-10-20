<?php

use App\Http\Controllers\Portal\LocaleController;
use App\Http\Controllers\Portal\LogoutController;
use App\Http\Middleware\PortalSession;
use App\Livewire\Portal;
use Illuminate\Support\Facades\Route;

/*
 * Spec 2026-10-18 tenant portal: the `tenant` guard only (plan ruling 1). Every record id is looked up inside the
 * signed-in tenant's records (plan ruling 18).
 */
Route::prefix('portal')->name('portal.')->middleware(PortalSession::class)->group(function () {
    Route::livewire('login', Portal\Login::class)->middleware('guest:tenant')->name('login');
    Route::post('locale', LocaleController::class)->middleware('throttle:30,1')->name('locale');

    Route::middleware('auth:tenant')->group(function () {
        Route::livewire('/', Portal\Home::class)->name('home');
        Route::post('logout', LogoutController::class)->name('logout');
    });
});

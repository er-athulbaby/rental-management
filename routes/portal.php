<?php

use App\Http\Controllers\Portal\DownloadController;
use App\Http\Controllers\Portal\LocaleController;
use App\Http\Controllers\Portal\LogoutController;
use App\Http\Controllers\Portal\OwnerDownloadController;
use App\Http\Middleware\PortalAccess;
use App\Http\Middleware\PortalSession;
use App\Livewire\Portal;
use Illuminate\Support\Facades\Route;

/*
 * Spec 2026-10-18 tenant portal and 2026-10-21 owner portal: the `tenant` guard only (tenant plan ruling 1). Tenant
 * pages need an active tenant on the account, owner pages an active owner (owner portal plan rulings 1 and 3). Every
 * record id is looked up inside the signed-in account's records (tenant plan ruling 18, owner portal plan ruling 10).
 */
Route::prefix('portal')->name('portal.')->middleware(PortalSession::class)->group(function () {
    Route::livewire('login', Portal\Login::class)->middleware('guest:tenant')->name('login');
    Route::post('locale', LocaleController::class)->middleware('throttle:30,1')->name('locale');

    Route::middleware('auth:tenant')->group(function () {
        Route::middleware(PortalAccess::class.':tenant')->group(function () {
            Route::livewire('/', Portal\Home::class)->name('home');
            Route::livewire('agreements', Portal\Agreements::class)->name('agreements');
            Route::get('agreements/{agreement}/contract', [DownloadController::class, 'agreement'])->whereNumber('agreement')->name('agreements.pdf');
            Route::livewire('invoices', Portal\Invoices::class)->name('invoices');
            Route::get('invoices/{invoice}/pdf', [DownloadController::class, 'invoice'])->whereNumber('invoice')->name('invoices.pdf');
            Route::livewire('payments', Portal\Payments::class)->name('payments');
            Route::get('payments/{payment}/receipt', [DownloadController::class, 'receipt'])->whereNumber('payment')->name('payments.receipt');
            Route::livewire('cheques', Portal\Cheques::class)->name('cheques');
            Route::livewire('statement', Portal\Statement::class)->name('statement');
            Route::get('statement/pdf', [DownloadController::class, 'statement'])->name('statement.pdf');
            Route::livewire('documents', Portal\Documents::class)->name('documents');
            Route::get('documents/{document}', [DownloadController::class, 'document'])->whereNumber('document')->name('documents.download');
            Route::livewire('maintenance', Portal\Maintenance::class)->name('maintenance');
            Route::livewire('maintenance/new', Portal\NewMaintenanceRequest::class)->name('maintenance.create');
            Route::livewire('maintenance/{maintenance}', Portal\MaintenanceShow::class)->whereNumber('maintenance')->name('maintenance.show');
        });

        Route::prefix('owner')->name('owner.')->middleware(PortalAccess::class.':owner')->group(function () {
            Route::livewire('/', Portal\Owner\Home::class)->name('home');
            Route::livewire('maintenance', Portal\Owner\Maintenance::class)->name('maintenance');
            Route::livewire('maintenance/{maintenance}', Portal\Owner\MaintenanceShow::class)->whereNumber('maintenance')->name('maintenance.show');
            Route::livewire('buildings', Portal\Owner\Buildings::class)->name('buildings');
            Route::livewire('contracts', Portal\Owner\Contracts::class)->name('contracts');
            Route::livewire('statements', Portal\Owner\Statements::class)->name('statements');
            Route::get('statements/{statement}/pdf', [OwnerDownloadController::class, 'statement'])->whereNumber('statement')->name('statements.pdf');
            Route::livewire('payments', Portal\Owner\Payments::class)->name('payments');
            Route::livewire('expenses', Portal\Owner\Expenses::class)->name('expenses');
        });

        Route::post('logout', LogoutController::class)->name('logout');
    });
});

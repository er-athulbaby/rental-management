<?php

use App\Http\Controllers\ImportTemplateController;
use App\Livewire\Approvals;
use App\Livewire\Buildings;
use App\Livewire\Customers;
use App\Livewire\Expenses;
use App\Livewire\Import;
use App\Livewire\OwnerContracts;
use App\Livewire\Owners;
use App\Livewire\Units;
use App\Models\Expense;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::livewire('approvals', Approvals\Index::class)->middleware('can:approvals.decide')->name('approvals.index');
    Route::livewire('buildings', Buildings\Index::class)->middleware('can:buildings.view')->name('buildings.index');
    Route::livewire('buildings/create', Buildings\Form::class)->middleware('can:buildings.manage')->name('buildings.create');
    Route::livewire('buildings/{building}/edit', Buildings\Form::class)->middleware('can:buildings.view')->name('buildings.edit');
    Route::livewire('customers', Customers\Index::class)->middleware('can:customers.view')->name('customers.index');
    Route::livewire('customers/create', Customers\Form::class)->middleware('can:customers.manage')->name('customers.create');
    Route::livewire('customers/{customer}/edit', Customers\Form::class)->middleware('can:customers.view')->name('customers.edit');
    Route::livewire('units', Units\Index::class)->middleware('can:buildings.view')->name('units.index');
    Route::livewire('units/create', Units\Form::class)->middleware('can:buildings.manage')->name('units.create');
    Route::livewire('units/{unit}/edit', Units\Form::class)->middleware('can:buildings.view')->name('units.edit');
    Route::livewire('owners', Owners\Index::class)->middleware('can:owners.view')->name('owners.index');
    Route::livewire('owners/create', Owners\Form::class)->middleware('can:owners.manage')->name('owners.create');
    Route::livewire('owners/{owner}/edit', Owners\Form::class)->middleware('can:owners.view')->name('owners.edit');
    Route::livewire('owner-contracts', OwnerContracts\Index::class)->middleware('can:owners.view')->name('owner-contracts.index');
    Route::livewire('owner-contracts/create', OwnerContracts\Form::class)->middleware('can:owners.manage')->name('owner-contracts.create');
    Route::livewire('owner-contracts/{contract}/edit', OwnerContracts\Form::class)->middleware('can:owners.manage')->name('owner-contracts.edit');
    Route::livewire('owner-contracts/{contract}', OwnerContracts\Show::class)->middleware('can:owners.view')->name('owner-contracts.show');
    Route::livewire('expenses', Expenses\Index::class)->middleware('can:viewAny,'.Expense::class)->name('expenses.index');
    Route::livewire('expenses/create', Expenses\Form::class)->middleware('can:expenses.manage')->name('expenses.create');
    Route::livewire('expenses/{expense}', Expenses\Show::class)->middleware('can:viewAny,'.Expense::class)->name('expenses.show');
    Route::livewire('import', Import\Index::class)->middleware('can:import.run')->name('import.index');
    Route::get('import/templates/{kind}', ImportTemplateController::class)->middleware('can:import.run')->name('import.template');
});

<?php

use App\Livewire\Buildings;
use App\Livewire\Owners;
use App\Livewire\Units;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::livewire('buildings', Buildings\Index::class)->middleware('can:buildings.view')->name('buildings.index');
    Route::livewire('buildings/create', Buildings\Form::class)->middleware('can:buildings.manage')->name('buildings.create');
    Route::livewire('buildings/{building}/edit', Buildings\Form::class)->middleware('can:buildings.view')->name('buildings.edit');
    Route::livewire('units', Units\Index::class)->middleware('can:buildings.view')->name('units.index');
    Route::livewire('units/create', Units\Form::class)->middleware('can:buildings.manage')->name('units.create');
    Route::livewire('units/{unit}/edit', Units\Form::class)->middleware('can:buildings.view')->name('units.edit');
    Route::livewire('owners', Owners\Index::class)->middleware('can:owners.view')->name('owners.index');
    Route::livewire('owners/create', Owners\Form::class)->middleware('can:owners.manage')->name('owners.create');
    Route::livewire('owners/{owner}/edit', Owners\Form::class)->middleware('can:owners.view')->name('owners.edit');
});

<?php

use App\Livewire\Buildings;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::livewire('buildings', Buildings\Index::class)->middleware('can:buildings.view')->name('buildings.index');
    Route::livewire('buildings/create', Buildings\Form::class)->middleware('can:buildings.manage')->name('buildings.create');
    Route::livewire('buildings/{building}/edit', Buildings\Form::class)->middleware('can:buildings.view')->name('buildings.edit');
});

<?php

use App\Livewire\Admin\AuditLog;
use App\Livewire\Admin\CompanySettings;
use App\Livewire\Admin\Roles;
use App\Livewire\Admin\Users;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('settings', CompanySettings::class)->middleware('can:settings.manage')->name('settings');

    Route::livewire('users', Users\Index::class)->middleware('can:users.manage')->name('users.index');
    Route::livewire('users/create', Users\Form::class)->middleware('can:users.manage')->name('users.create');
    Route::livewire('users/{user}/edit', Users\Form::class)->middleware('can:users.manage')->name('users.edit');

    Route::livewire('roles', Roles\Index::class)->middleware('can:roles.manage')->name('roles.index');
    Route::livewire('roles/{role}/edit', Roles\Edit::class)->middleware('can:roles.manage')->name('roles.edit');

    Route::livewire('audit', AuditLog::class)->middleware('can:audit.view')->name('audit');
});

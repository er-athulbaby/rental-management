<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::get('documents/{document}', [DocumentController::class, 'download'])->name('documents.download');
});

require __DIR__.'/settings.php';

require __DIR__.'/admin.php';

require __DIR__.'/property.php';

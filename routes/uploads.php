<?php

use Illuminate\Support\Facades\Route;
use TornadoEngine\Uploads\Http\UploadsController;

Route::middleware(['web', 'auth', 'can:uploads.use'])->prefix('uploads')->name('uploads.')->group(function () {
    Route::get('/', [UploadsController::class, 'index'])->name('index');
    Route::post('/', [UploadsController::class, 'store'])->name('store');
    Route::post('/{upload}/share', [UploadsController::class, 'share'])->name('share');
    Route::delete('/{upload}', [UploadsController::class, 'destroy'])->name('destroy');
});

Route::middleware(['web', 'auth', 'can:administration'])->post('/administration/uploads', [UploadsController::class, 'settings'])->name('uploads.settings');

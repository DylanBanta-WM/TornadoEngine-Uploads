<?php

use Illuminate\Support\Facades\Route;
use TornadoEngine\Uploads\Http\UploadsController;

// For other modules' pages (Chat's message box): upload, review, preview, delete. There's no page of its own.
Route::middleware(['web', 'auth', 'can:uploads.use'])->prefix('uploads')->name('uploads.')->group(function () {
    Route::post('/', [UploadsController::class, 'store'])->name('store');
    Route::post('/{upload}/review', [UploadsController::class, 'review'])->name('review');
    Route::get('/{upload}/preview', [UploadsController::class, 'preview'])->name('preview');
    Route::delete('/{upload}', [UploadsController::class, 'destroy'])->name('destroy');
});

Route::middleware(['web', 'auth', 'can:administration'])->prefix('administration/uploads')->name('uploads.')->group(function () {
    Route::post('/', [UploadsController::class, 'settings'])->name('settings');
    Route::post('/logos', [UploadsController::class, 'addLogo'])->name('logos.add');
    Route::get('/logos/{logo}', [UploadsController::class, 'logo'])->name('logos.show');
    Route::delete('/logos/{logo}', [UploadsController::class, 'removeLogo'])->name('logos.remove');
});

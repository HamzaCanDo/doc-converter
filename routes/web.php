<?php

use App\Http\Controllers\DocConvertController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DocConvertController::class, 'index'])->name('doc.index');
Route::post('/convert', [DocConvertController::class, 'convert'])->name('doc.convert');
Route::get('/download/{fileId}/{format}', [DocConvertController::class, 'download'])
    ->name('doc.download')
    ->middleware(['signed', 'throttle:downloads']);
Route::get('/upload-download/{token}/{format}', [DocConvertController::class, 'downloadUpload'])
    ->name('upload.download')
    ->middleware(['signed', 'throttle:downloads']);

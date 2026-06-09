<?php

use App\Http\Controllers\EmailUnsubscribeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('signed')->group(function (): void {
    Route::get('email/unsubscribe', [EmailUnsubscribeController::class, 'show'])->name('email.unsubscribe');
    Route::post('email/unsubscribe', [EmailUnsubscribeController::class, 'update'])->name('email.unsubscribe.one-click');
});

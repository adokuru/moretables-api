<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GuestAuthController;
use App\Http\Controllers\Api\V1\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('google', [SocialAuthController::class, 'google']);
    Route::post('apple', [SocialAuthController::class, 'apple']);
    Route::post('staff/login', [AuthController::class, 'staffLogin']);
    Route::post('staff/verify-2fa', [AuthController::class, 'verifyStaffLogin']);
    Route::post('password/forgot', [AuthController::class, 'forgotPassword']);
    Route::post('password/reset', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::prefix('guest')->group(function (): void {
    Route::post('start', [GuestAuthController::class, 'start']);
    Route::post('verify-otp', [GuestAuthController::class, 'verifyOtp']);
    Route::post('resend-otp', [GuestAuthController::class, 'resendOtp']);
    Route::middleware('auth:sanctum')->post('complete-profile', [GuestAuthController::class, 'completeProfile']);
});

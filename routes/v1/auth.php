<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GuestAuthController;
use App\Http\Controllers\Api\V1\ProfileSettingsController;
use App\Http\Controllers\Api\V1\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('start', [GuestAuthController::class, 'start']);
    Route::post('verify-otp', [GuestAuthController::class, 'verifyOtp']);
    Route::post('resend-otp', [GuestAuthController::class, 'resendOtp']);
    Route::post('google', [SocialAuthController::class, 'google']);
    Route::post('apple', [SocialAuthController::class, 'apple']);
    Route::post('staff/login', [AuthController::class, 'staffLogin']);
    Route::post('staff/verify-2fa', [AuthController::class, 'verifyStaffLogin']);
    Route::post('password/forgot', [AuthController::class, 'forgotPassword']);
    Route::post('password/reset', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('complete-profile', [GuestAuthController::class, 'completeProfile']);
        Route::post('logout', [GuestAuthController::class, 'logout']);
        Route::get('me', [GuestAuthController::class, 'me']);
        Route::get('profile', [ProfileSettingsController::class, 'show']);
        Route::patch('profile', [ProfileSettingsController::class, 'update']);
        Route::post('profile-picture', [ProfileSettingsController::class, 'updateProfilePicture']);
        Route::get('staff/profile', [AuthController::class, 'profile']);
        Route::patch('staff/profile', [AuthController::class, 'updateProfile']);
    });
});

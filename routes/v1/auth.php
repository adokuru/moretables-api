<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GuestAuthController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProfileSettingsController;
use App\Http\Controllers\Api\V1\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('start', [GuestAuthController::class, 'start'])->middleware('throttle:auth-initiate');
    Route::post('verify-otp', [GuestAuthController::class, 'verifyOtp'])->middleware('throttle:auth-verify');
    Route::post('resend-otp', [GuestAuthController::class, 'resendOtp'])->middleware('throttle:auth-initiate');
    Route::post('google', [SocialAuthController::class, 'google'])->middleware('throttle:public-write');
    Route::post('apple', [SocialAuthController::class, 'apple'])->middleware('throttle:public-write');
    Route::post('staff/login', [AuthController::class, 'staffLogin'])->middleware('throttle:auth-initiate');
    Route::post('staff/verify-2fa', [AuthController::class, 'verifyStaffLogin'])->middleware('throttle:auth-verify');
    Route::post('password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth-initiate');
    Route::post('password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:public-write');

    Route::middleware(['auth:sanctum', 'throttle:customer-api'])->group(function (): void {
        Route::post('complete-profile', [GuestAuthController::class, 'completeProfile']);
        Route::post('logout', [GuestAuthController::class, 'logout']);
        Route::get('me', [GuestAuthController::class, 'me']);
        Route::get('profile', [ProfileSettingsController::class, 'show']);
        Route::patch('profile', [ProfileSettingsController::class, 'update']);
        Route::patch('profile/phone', [ProfileSettingsController::class, 'updatePhone']);
        Route::patch('profile/password', [ProfileSettingsController::class, 'changePassword']);
        Route::post('profile-picture', [ProfileSettingsController::class, 'updateProfilePicture']);
        Route::get('staff/profile', [AuthController::class, 'profile']);
        Route::patch('staff/profile', [AuthController::class, 'updateProfile']);
        Route::patch('staff/profile/password', [AuthController::class, 'changeStaffPassword']);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    });
});

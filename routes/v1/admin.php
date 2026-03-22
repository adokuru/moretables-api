<?php

use App\Http\Controllers\Api\V1\AdminAuditLogController;
use App\Http\Controllers\Api\V1\AdminAuthController;
use App\Http\Controllers\Api\V1\AdminBusinessOnboardingController;
use App\Http\Controllers\Api\V1\AdminDashboardController;
use App\Http\Controllers\Api\V1\AdminOnboardingRequestController;
use App\Http\Controllers\Api\V1\AdminOrganizationController;
use App\Http\Controllers\Api\V1\AdminReservationController;
use App\Http\Controllers\Api\V1\AdminRestaurantController;
use App\Http\Controllers\Api\V1\AdminRestaurantReviewController;
use App\Http\Controllers\Api\V1\AdminRewardProgramController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AdminUserRoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/auth')->group(function (): void {
    Route::post('login', [AdminAuthController::class, 'login']);
    Route::post('verify-2fa', [AdminAuthController::class, 'verify']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::get('me', [AdminAuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->prefix('admin')->group(function (): void {
    Route::get('dashboard', [AdminDashboardController::class, 'index']);

    Route::get('reward-program', [AdminRewardProgramController::class, 'show']);
    Route::patch('reward-program', [AdminRewardProgramController::class, 'update']);
    Route::post('users/{user}/reward-points', [AdminRewardProgramController::class, 'storePoints']);

    Route::get('users', [AdminUserController::class, 'index']);
    Route::post('users', [AdminUserController::class, 'store']);
    Route::get('organizations', [AdminOrganizationController::class, 'index']);
    Route::post('organizations', [AdminOrganizationController::class, 'store']);
    Route::post('organizations/onboard', [AdminBusinessOnboardingController::class, 'store']);
    Route::get('organizations/{organization}', [AdminOrganizationController::class, 'show']);
    Route::patch('organizations/{organization}', [AdminOrganizationController::class, 'update']);
    Route::delete('organizations/{organization}', [AdminOrganizationController::class, 'destroy']);

    Route::get('restaurants', [AdminRestaurantController::class, 'index']);
    Route::post('restaurants', [AdminRestaurantController::class, 'store']);
    Route::get('restaurants/{restaurant}', [AdminRestaurantController::class, 'show']);
    Route::patch('restaurants/{restaurant}', [AdminRestaurantController::class, 'update']);
    Route::delete('restaurants/{restaurant}', [AdminRestaurantController::class, 'destroy']);
    Route::post('restaurants/{restaurant}/invite-owner', [AdminRestaurantController::class, 'inviteOwner']);
    Route::patch('restaurants/{restaurant}/status', [AdminRestaurantController::class, 'updateStatus']);

    Route::get('reservations/analytics', [AdminReservationController::class, 'analytics']);
    Route::get('reservations', [AdminReservationController::class, 'index']);
    Route::post('reservations', [AdminReservationController::class, 'store']);
    Route::get('reservations/{reservation}', [AdminReservationController::class, 'show']);
    Route::patch('reservations/{reservation}', [AdminReservationController::class, 'update']);
    Route::delete('reservations/{reservation}', [AdminReservationController::class, 'destroy']);

    Route::get('reviews', [AdminRestaurantReviewController::class, 'index']);
    Route::post('reviews', [AdminRestaurantReviewController::class, 'store']);
    Route::get('reviews/{review}', [AdminRestaurantReviewController::class, 'show']);
    Route::patch('reviews/{review}', [AdminRestaurantReviewController::class, 'update']);
    Route::delete('reviews/{review}', [AdminRestaurantReviewController::class, 'destroy']);

    Route::get('onboarding-requests', [AdminOnboardingRequestController::class, 'index']);
    Route::post('onboarding-requests', [AdminOnboardingRequestController::class, 'store']);
    Route::get('onboarding-requests/{onboardingRequest}', [AdminOnboardingRequestController::class, 'show']);
    Route::patch('onboarding-requests/{onboardingRequest}', [AdminOnboardingRequestController::class, 'update']);
    Route::delete('onboarding-requests/{onboardingRequest}', [AdminOnboardingRequestController::class, 'destroy']);

    Route::get('users/{user}', [AdminUserController::class, 'show']);
    Route::put('users/{user}/roles', [AdminUserRoleController::class, 'update']);
    Route::patch('users/{user}', [AdminUserController::class, 'update']);
    Route::delete('users/{user}', [AdminUserController::class, 'destroy']);

    Route::get('audit-logs', [AdminAuditLogController::class, 'index']);
});

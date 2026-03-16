<?php

use App\Http\Controllers\Api\V1\CustomerReservationController;
use App\Http\Controllers\Api\V1\CustomerWaitlistController;
use App\Http\Controllers\Api\V1\ExpoPushTokenController;
use App\Http\Controllers\Api\V1\OnboardingRequestController;
use App\Http\Controllers\Api\V1\PublicRestaurantController;
use Illuminate\Support\Facades\Route;

Route::post('onboarding-requests', [OnboardingRequestController::class, 'store']);

Route::get('restaurants', [PublicRestaurantController::class, 'index']);
Route::get('restaurants/{restaurant}', [PublicRestaurantController::class, 'show']);
Route::get('restaurants/{restaurant}/availability', [PublicRestaurantController::class, 'availability']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('me/expo-push-tokens', [ExpoPushTokenController::class, 'store']);
    Route::delete('me/expo-push-tokens', [ExpoPushTokenController::class, 'destroy']);

    Route::get('me/reservations', [CustomerReservationController::class, 'index']);
    Route::post('reservations', [CustomerReservationController::class, 'store']);
    Route::get('reservations/{reservation}', [CustomerReservationController::class, 'show']);
    Route::patch('reservations/{reservation}', [CustomerReservationController::class, 'update']);
    Route::delete('reservations/{reservation}', [CustomerReservationController::class, 'destroy']);

    Route::get('me/waitlist-entries', [CustomerWaitlistController::class, 'index']);
    Route::post('waitlist-entries', [CustomerWaitlistController::class, 'store']);
    Route::post('waitlist-entries/{waitlistEntry}/accept', [CustomerWaitlistController::class, 'accept']);
    Route::post('waitlist-entries/{waitlistEntry}/decline', [CustomerWaitlistController::class, 'decline']);
});

<?php

use App\Http\Controllers\Api\V1\CustomerReservationController;
use App\Http\Controllers\Api\V1\CustomerRestaurantListController;
use App\Http\Controllers\Api\V1\CustomerSavedRestaurantController;
use App\Http\Controllers\Api\V1\CustomerWaitlistController;
use App\Http\Controllers\Api\V1\ExpoPushTokenController;
use App\Http\Controllers\Api\V1\OnboardingRequestController;
use App\Http\Controllers\Api\V1\PublicRestaurantController;
use App\Http\Controllers\Api\V1\PublicRestaurantDiscoveryController;
use App\Http\Controllers\Api\V1\PublicRestaurantViewController;
use App\Http\Controllers\Api\V1\RestaurantReviewController;
use Illuminate\Support\Facades\Route;

Route::post('onboarding-requests', [OnboardingRequestController::class, 'store']);

Route::get('restaurants/discovery', [PublicRestaurantDiscoveryController::class, 'index']);
Route::get('restaurants/discovery/{section}', [PublicRestaurantDiscoveryController::class, 'show']);

Route::get('restaurants', [PublicRestaurantController::class, 'index']);
Route::get('restaurants/{restaurant}', [PublicRestaurantController::class, 'show']);
Route::get('restaurants/{restaurant}/availability', [PublicRestaurantController::class, 'availability']);
Route::post('restaurants/{restaurant}/views', [PublicRestaurantViewController::class, 'store']);
Route::get('restaurants/{restaurant}/reviews', [RestaurantReviewController::class, 'index']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('me/expo-push-tokens', [ExpoPushTokenController::class, 'store']);
    Route::delete('me/expo-push-tokens', [ExpoPushTokenController::class, 'destroy']);

    Route::get('me/saved-restaurants', [CustomerSavedRestaurantController::class, 'index']);
    Route::post('restaurants/{restaurant}/save', [CustomerSavedRestaurantController::class, 'store']);
    Route::delete('restaurants/{restaurant}/save', [CustomerSavedRestaurantController::class, 'destroy']);

    Route::get('me/restaurant-lists', [CustomerRestaurantListController::class, 'index']);
    Route::post('me/restaurant-lists', [CustomerRestaurantListController::class, 'store']);
    Route::patch('me/restaurant-lists/{restaurantList}', [CustomerRestaurantListController::class, 'update']);
    Route::delete('me/restaurant-lists/{restaurantList}', [CustomerRestaurantListController::class, 'destroy']);
    Route::post('me/restaurant-lists/{restaurantList}/restaurants', [CustomerRestaurantListController::class, 'addRestaurant']);
    Route::delete('me/restaurant-lists/{restaurantList}/restaurants/{restaurant}', [CustomerRestaurantListController::class, 'removeRestaurant']);

    Route::post('restaurants/{restaurant}/reviews', [RestaurantReviewController::class, 'store']);
    Route::patch('restaurants/{restaurant}/reviews/{review}', [RestaurantReviewController::class, 'update']);
    Route::delete('restaurants/{restaurant}/reviews/{review}', [RestaurantReviewController::class, 'destroy']);

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

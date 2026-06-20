<?php

use App\Http\Controllers\Api\V1\CustomerAvailabilityAlertController;
use App\Http\Controllers\Api\V1\CustomerReservationController;
use App\Http\Controllers\Api\V1\CustomerRestaurantListController;
use App\Http\Controllers\Api\V1\CustomerRewardController;
use App\Http\Controllers\Api\V1\CustomerSavedRestaurantController;
use App\Http\Controllers\Api\V1\CustomerWaitlistController;
use App\Http\Controllers\Api\V1\ExpoPushTokenController;
use App\Http\Controllers\Api\V1\OnboardingRequestController;
use App\Http\Controllers\Api\V1\PublicCuisineOptionController;
use App\Http\Controllers\Api\V1\PublicMoretableLineupController;
use App\Http\Controllers\Api\V1\PublicRestaurantController;
use App\Http\Controllers\Api\V1\PublicRestaurantDiscoveryController;
use App\Http\Controllers\Api\V1\PublicRestaurantViewController;
use App\Http\Controllers\Api\V1\RestaurantReviewController;
use App\Http\Controllers\Api\V1\StatusIconController;
use App\Http\Controllers\Api\V1\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle']);

Route::post('onboarding-requests', [OnboardingRequestController::class, 'store'])->middleware('throttle:public-write');

Route::get('cuisine-options', [PublicCuisineOptionController::class, 'index'])->middleware('throttle:public-read');

Route::get('reference/status-icons', [StatusIconController::class, 'index'])->middleware('throttle:public-read');

Route::get('moretable-lineups', [PublicMoretableLineupController::class, 'index'])->middleware('throttle:public-read');
Route::get('moretable-lineups/{slug}', [PublicMoretableLineupController::class, 'show'])->middleware('throttle:public-read');

Route::get('search', [PublicRestaurantController::class, 'search'])->middleware('throttle:public-expensive');
Route::get('reviews/random', [PublicRestaurantController::class, 'randomReviews'])->middleware('throttle:public-read');
Route::get('restaurants/discovery', [PublicRestaurantDiscoveryController::class, 'index'])->middleware('throttle:public-expensive');
Route::get('restaurants/discovery/{section}', [PublicRestaurantDiscoveryController::class, 'show'])->middleware('throttle:public-expensive');

Route::get('restaurants', [PublicRestaurantController::class, 'index'])->middleware('throttle:public-read');
Route::get('restaurants/{restaurant:slug}', [PublicRestaurantController::class, 'show'])->middleware('throttle:public-read');
Route::get('restaurants/{restaurant:slug}/availability', [PublicRestaurantController::class, 'availability'])->middleware('throttle:public-expensive');
Route::post('restaurants/{restaurant:slug}/views', [PublicRestaurantViewController::class, 'store'])->middleware('throttle:public-write');
Route::get('restaurants/{restaurant:slug}/reviews', [RestaurantReviewController::class, 'index'])->middleware('throttle:public-read');

Route::middleware(['auth:sanctum', 'throttle:customer-api'])->group(function (): void {
    Route::post('me/expo-push-tokens', [ExpoPushTokenController::class, 'store']);
    Route::delete('me/expo-push-tokens', [ExpoPushTokenController::class, 'destroy']);

    Route::get('me/rewards/status', [CustomerRewardController::class, 'status']);
    Route::get('me/rewards/transactions', [CustomerRewardController::class, 'transactions']);
    Route::post('me/rewards/redeem', [CustomerRewardController::class, 'redeem']);

    Route::get('me/saved-restaurants', [CustomerSavedRestaurantController::class, 'index']);
    Route::post('restaurants/{restaurant:slug}/save', [CustomerSavedRestaurantController::class, 'store']);
    Route::delete('restaurants/{restaurant:slug}/save', [CustomerSavedRestaurantController::class, 'destroy']);

    Route::get('me/restaurant-lists', [CustomerRestaurantListController::class, 'index']);
    Route::post('me/restaurant-lists', [CustomerRestaurantListController::class, 'store']);
    Route::patch('me/restaurant-lists/{restaurantList}', [CustomerRestaurantListController::class, 'update']);
    Route::delete('me/restaurant-lists/{restaurantList}', [CustomerRestaurantListController::class, 'destroy']);
    Route::post('me/restaurant-lists/{restaurantList}/restaurants', [CustomerRestaurantListController::class, 'addRestaurant']);
    Route::delete('me/restaurant-lists/{restaurantList}/restaurants/{restaurant:slug}', [CustomerRestaurantListController::class, 'removeRestaurant']);

    Route::post('restaurants/{restaurant:slug}/reviews', [RestaurantReviewController::class, 'store']);
    Route::patch('restaurants/{restaurant:slug}/reviews/{review}', [RestaurantReviewController::class, 'update']);
    Route::delete('restaurants/{restaurant:slug}/reviews/{review}', [RestaurantReviewController::class, 'destroy']);

    Route::get('me/reservations', [CustomerReservationController::class, 'index']);
    Route::post('reservations', [CustomerReservationController::class, 'store']);
    Route::get('reservations/{reservation}', [CustomerReservationController::class, 'show']);
    Route::patch('reservations/{reservation}', [CustomerReservationController::class, 'update']);
    Route::post('reservations/{reservation}/guests', [CustomerReservationController::class, 'addGuests']);
    Route::put('reservations/{reservation}/guests', [CustomerReservationController::class, 'updateGuests']);
    Route::delete('reservations/{reservation}/guests/{reservationGuest}', [CustomerReservationController::class, 'removeGuest']);
    Route::delete('reservations/{reservation}', [CustomerReservationController::class, 'destroy']);

    Route::get('me/waitlist-entries', [CustomerWaitlistController::class, 'index']);
    Route::post('waitlist-entries', [CustomerWaitlistController::class, 'store']);
    Route::post('waitlist-entries/{waitlistEntry}/accept', [CustomerWaitlistController::class, 'accept']);
    Route::post('waitlist-entries/{waitlistEntry}/decline', [CustomerWaitlistController::class, 'decline']);

    Route::get('me/availability-alerts', [CustomerAvailabilityAlertController::class, 'index']);
    Route::post('restaurants/{restaurant:slug}/availability-alerts', [CustomerAvailabilityAlertController::class, 'store']);
    Route::delete('availability-alerts/{availabilityAlert}', [CustomerAvailabilityAlertController::class, 'destroy']);
});

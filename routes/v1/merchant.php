<?php

use App\Http\Controllers\Api\V1\MerchantBillingController;
use App\Http\Controllers\Api\V1\MerchantDiningAreaController;
use App\Http\Controllers\Api\V1\MerchantGuestCommunicationController;
use App\Http\Controllers\Api\V1\MerchantMenuItemController;
use App\Http\Controllers\Api\V1\MerchantMenuItemMediaController;
use App\Http\Controllers\Api\V1\MerchantReservationController;
use App\Http\Controllers\Api\V1\MerchantRestaurantController;
use App\Http\Controllers\Api\V1\MerchantRestaurantGalleryCategoryController;
use App\Http\Controllers\Api\V1\MerchantRestaurantMediaController;
use App\Http\Controllers\Api\V1\MerchantRestaurantOnboardingController;
use App\Http\Controllers\Api\V1\MerchantRestaurantReviewController;
use App\Http\Controllers\Api\V1\MerchantRestaurantStaffController;
use App\Http\Controllers\Api\V1\MerchantTableController;
use App\Http\Controllers\Api\V1\MerchantWaitlistController;
use App\Http\Controllers\Api\V1\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('billing/paystack/webhook', PaystackWebhookController::class);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('merchant/restaurants', [MerchantRestaurantController::class, 'index']);
    Route::get('merchant/billing/plans', [MerchantBillingController::class, 'plans']);
});

Route::middleware('auth:sanctum')->prefix('merchant/restaurants/{restaurant}')->group(function (): void {
    Route::prefix('billing')->group(function (): void {
        Route::get('/', [MerchantBillingController::class, 'show']);
        Route::post('checkout', [MerchantBillingController::class, 'checkout']);
        Route::get('verify/{reference}', [MerchantBillingController::class, 'verify']);
        Route::get('invoices', [MerchantBillingController::class, 'invoices']);
        Route::get('invoices/{invoice}/download', [MerchantBillingController::class, 'downloadInvoice']);
    });

    Route::middleware('merchant.billing.active')->group(function (): void {
        Route::get('/', [MerchantRestaurantController::class, 'show']);
        Route::patch('/', [MerchantRestaurantController::class, 'update']);

        Route::get('guest-communication', [MerchantGuestCommunicationController::class, 'show']);
        Route::patch('guest-communication/automated-messaging', [MerchantGuestCommunicationController::class, 'updateAutomatedMessaging']);
        Route::patch('guest-communication/reservation-messaging', [MerchantGuestCommunicationController::class, 'updateReservationMessaging']);

        Route::prefix('onboarding')->group(function (): void {
            Route::patch('contact-cuisine-price', [MerchantRestaurantOnboardingController::class, 'updateContactCuisinePrice']);
            Route::post('profile-photo', [MerchantRestaurantOnboardingController::class, 'uploadProfilePhoto']);
            Route::patch('description', [MerchantRestaurantOnboardingController::class, 'updateDescription']);
            Route::patch('internal-notes', [MerchantRestaurantOnboardingController::class, 'updateInternalNotes']);
            Route::put('business-hours', [MerchantRestaurantOnboardingController::class, 'updateBusinessHours']);
            Route::get('meal-types', [MerchantRestaurantOnboardingController::class, 'indexMealTypes']);
            Route::post('meal-types', [MerchantRestaurantOnboardingController::class, 'storeMealType']);
            Route::patch('meal-types/{mealType}', [MerchantRestaurantOnboardingController::class, 'updateMealType']);
            Route::delete('meal-types/{mealType}', [MerchantRestaurantOnboardingController::class, 'destroyMealType']);
            Route::post('contact-email/send-code', [MerchantRestaurantOnboardingController::class, 'sendEmailVerificationCode']);
            Route::post('contact-email/verify', [MerchantRestaurantOnboardingController::class, 'verifyEmail']);
            Route::get('data', [MerchantRestaurantOnboardingController::class, 'showData']);
            Route::get('status', [MerchantRestaurantOnboardingController::class, 'showStatus']);
            Route::patch('status', [MerchantRestaurantOnboardingController::class, 'updateStatus']);
        });
        Route::get('staff', [MerchantRestaurantStaffController::class, 'index']);
        Route::post('staff', [MerchantRestaurantStaffController::class, 'store']);
        Route::patch('staff/{user}', [MerchantRestaurantStaffController::class, 'update']);
        Route::delete('staff/{user}', [MerchantRestaurantStaffController::class, 'destroy']);
        Route::get('gallery', [MerchantRestaurantMediaController::class, 'gallery']);
        Route::get('gallery/categories', [MerchantRestaurantGalleryCategoryController::class, 'index']);
        Route::post('gallery/categories', [MerchantRestaurantGalleryCategoryController::class, 'store']);
        Route::patch('gallery/categories/{galleryCategory}', [MerchantRestaurantGalleryCategoryController::class, 'update']);
        Route::delete('gallery/categories/{galleryCategory}', [MerchantRestaurantGalleryCategoryController::class, 'destroy']);

        Route::post('media', [MerchantRestaurantMediaController::class, 'store']);
        Route::patch('media/{media}', [MerchantRestaurantMediaController::class, 'update']);
        Route::post('media/reorder', [MerchantRestaurantMediaController::class, 'reorder']);
        Route::post('media/{media}/feature', [MerchantRestaurantMediaController::class, 'feature']);
        Route::delete('media/{media}', [MerchantRestaurantMediaController::class, 'destroy']);

        Route::get('dining-areas', [MerchantDiningAreaController::class, 'index']);
        Route::post('dining-areas', [MerchantDiningAreaController::class, 'store']);
        Route::patch('dining-areas/{diningArea}', [MerchantDiningAreaController::class, 'update']);
        Route::delete('dining-areas/{diningArea}', [MerchantDiningAreaController::class, 'destroy']);

        Route::get('tables', [MerchantTableController::class, 'index']);
        Route::post('tables', [MerchantTableController::class, 'store']);
        Route::patch('tables/{table}', [MerchantTableController::class, 'update']);
        Route::patch('tables/{table}/status', [MerchantTableController::class, 'updateStatus']);
        Route::delete('tables/{table}', [MerchantTableController::class, 'destroy']);

        Route::get('menu-items', [MerchantMenuItemController::class, 'index']);
        Route::post('menu-items', [MerchantMenuItemController::class, 'store']);
        Route::patch('menu-items/{menuItem}', [MerchantMenuItemController::class, 'update']);
        Route::delete('menu-items/{menuItem}', [MerchantMenuItemController::class, 'destroy']);

        Route::post('menu-items/{menuItem}/media', [MerchantMenuItemMediaController::class, 'store']);
        Route::patch('menu-items/{menuItem}/media/{media}', [MerchantMenuItemMediaController::class, 'update']);
        Route::post('menu-items/{menuItem}/media/reorder', [MerchantMenuItemMediaController::class, 'reorder']);
        Route::post('menu-items/{menuItem}/media/{media}/feature', [MerchantMenuItemMediaController::class, 'feature']);
        Route::delete('menu-items/{menuItem}/media/{media}', [MerchantMenuItemMediaController::class, 'destroy']);

        Route::get('reservations', [MerchantReservationController::class, 'index']);
        Route::post('reservations', [MerchantReservationController::class, 'store']);
        Route::get('reservations/{reservation}', [MerchantReservationController::class, 'show']);
        Route::patch('reservations/{reservation}', [MerchantReservationController::class, 'update']);
        Route::post('reservations/{reservation}/assign-table', [MerchantReservationController::class, 'assignTable']);
        Route::post('reservations/{reservation}/seat', [MerchantReservationController::class, 'seat']);
        Route::post('reservations/{reservation}/complete', [MerchantReservationController::class, 'complete']);
        Route::post('reservations/{reservation}/cancel', [MerchantReservationController::class, 'cancel']);

        Route::get('reviews/aggregate', [MerchantRestaurantReviewController::class, 'aggregate']);
        Route::get('reviews', [MerchantRestaurantReviewController::class, 'index']);

        Route::get('waitlist-entries', [MerchantWaitlistController::class, 'index']);
        Route::post('waitlist-entries', [MerchantWaitlistController::class, 'store']);
        Route::post('waitlist-entries/{waitlistEntry}/notify', [MerchantWaitlistController::class, 'notify']);
        Route::post('waitlist-entries/{waitlistEntry}/assign-table', [MerchantWaitlistController::class, 'assignTable']);
    });
});

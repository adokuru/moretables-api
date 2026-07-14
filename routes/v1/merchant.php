<?php

use App\Http\Controllers\Api\V1\FrontOfHouseController;
use App\Http\Controllers\Api\V1\FrontOfHouseFloorPlanController;
use App\Http\Controllers\Api\V1\FrontOfHouseOperationsController;
use App\Http\Controllers\Api\V1\FrontOfHouseShiftNoteController;
use App\Http\Controllers\Api\V1\FrontOfHouseShiftOverviewController;
use App\Http\Controllers\Api\V1\FrontOfHouseTimelineController;
use App\Http\Controllers\Api\V1\GuestbookController;
use App\Http\Controllers\Api\V1\MerchantAccessConfigController;
use App\Http\Controllers\Api\V1\MerchantBillingController;
use App\Http\Controllers\Api\V1\MerchantDiningAreaController;
use App\Http\Controllers\Api\V1\MerchantDiningSpotController;
use App\Http\Controllers\Api\V1\MerchantGuestCommunicationController;
use App\Http\Controllers\Api\V1\MerchantMenuCategoryController;
use App\Http\Controllers\Api\V1\MerchantMenuItemController;
use App\Http\Controllers\Api\V1\MerchantMenuItemMediaController;
use App\Http\Controllers\Api\V1\MerchantReportingController;
use App\Http\Controllers\Api\V1\MerchantReservationController;
use App\Http\Controllers\Api\V1\MerchantRestaurantBookingPolicyController;
use App\Http\Controllers\Api\V1\MerchantRestaurantBroadcastController;
use App\Http\Controllers\Api\V1\MerchantRestaurantCancellationPolicyController;
use App\Http\Controllers\Api\V1\MerchantRestaurantController;
use App\Http\Controllers\Api\V1\MerchantRestaurantGalleryCategoryController;
use App\Http\Controllers\Api\V1\MerchantRestaurantGuestController;
use App\Http\Controllers\Api\V1\MerchantRestaurantInternalNoteController;
use App\Http\Controllers\Api\V1\MerchantRestaurantMediaController;
use App\Http\Controllers\Api\V1\MerchantRestaurantMenuDocumentController;
use App\Http\Controllers\Api\V1\MerchantRestaurantOnboardingController;
use App\Http\Controllers\Api\V1\MerchantRestaurantOverviewController;
use App\Http\Controllers\Api\V1\MerchantRestaurantReviewController;
use App\Http\Controllers\Api\V1\MerchantRestaurantRewardStatusController;
use App\Http\Controllers\Api\V1\MerchantRestaurantServerController;
use App\Http\Controllers\Api\V1\MerchantRestaurantSettingsController;
use App\Http\Controllers\Api\V1\MerchantRestaurantShiftController;
use App\Http\Controllers\Api\V1\MerchantRestaurantSpecialDayController;
use App\Http\Controllers\Api\V1\MerchantRestaurantStaffController;
use App\Http\Controllers\Api\V1\MerchantRestaurantWidgetSettingsController;
use App\Http\Controllers\Api\V1\MerchantRewardRuleController;
use App\Http\Controllers\Api\V1\MerchantTableCombinationController;
use App\Http\Controllers\Api\V1\MerchantTableController;
use App\Http\Controllers\Api\V1\MerchantWaitlistController;
use App\Http\Controllers\Api\V1\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('billing/paystack/webhook', PaystackWebhookController::class);

Route::middleware(['auth:sanctum', 'throttle:merchant-api'])->group(function (): void {
    Route::get('merchant/restaurants', [MerchantRestaurantController::class, 'index']);
    Route::get('merchant/billing/plans', [MerchantBillingController::class, 'plans']);
});

Route::middleware(['auth:sanctum', 'merchant.access', 'throttle:merchant-api'])->prefix('merchant/restaurants/{restaurant}')->group(function (): void {
    Route::prefix('billing')->group(function (): void {
        Route::get('/', [MerchantBillingController::class, 'show']);
        Route::post('checkout', [MerchantBillingController::class, 'checkout']);
        Route::post('upgrade', [MerchantBillingController::class, 'upgrade']);
        Route::get('verify/{reference}', [MerchantBillingController::class, 'verify']);
        Route::get('invoices', [MerchantBillingController::class, 'invoices']);
        Route::get('invoices/{invoice}/download', [MerchantBillingController::class, 'downloadInvoice']);
    });

    Route::middleware('merchant.billing.active')->group(function (): void {
        Route::get('/', [MerchantRestaurantController::class, 'show']);
        Route::get('overview', [MerchantRestaurantOverviewController::class, 'show']);
        Route::patch('/', [MerchantRestaurantController::class, 'update']);
        Route::post('menu-document', [MerchantRestaurantMenuDocumentController::class, 'store']);

        Route::get('settings', [MerchantRestaurantSettingsController::class, 'show']);
        Route::patch('settings', [MerchantRestaurantSettingsController::class, 'update']);

        Route::get('special-days', [MerchantRestaurantSpecialDayController::class, 'index']);
        Route::post('special-days', [MerchantRestaurantSpecialDayController::class, 'store']);
        Route::get('special-days/{specialDay}', [MerchantRestaurantSpecialDayController::class, 'show']);
        Route::match(['put', 'patch'], 'special-days/{specialDay}', [MerchantRestaurantSpecialDayController::class, 'update']);
        Route::delete('special-days/{specialDay}', [MerchantRestaurantSpecialDayController::class, 'destroy']);

        Route::get('shifts', [MerchantRestaurantShiftController::class, 'index']);
        Route::post('shifts', [MerchantRestaurantShiftController::class, 'store']);
        Route::get('shifts/{shift}', [MerchantRestaurantShiftController::class, 'show']);
        Route::match(['put', 'patch'], 'shifts/{shift}', [MerchantRestaurantShiftController::class, 'update']);
        Route::delete('shifts/{shift}', [MerchantRestaurantShiftController::class, 'destroy']);

        Route::get('rewards/status', [MerchantRestaurantRewardStatusController::class, 'show']);

        Route::get('reward-rules', [MerchantRewardRuleController::class, 'index']);
        Route::post('reward-rules', [MerchantRewardRuleController::class, 'store']);
        Route::get('reward-rules/{rewardRule}', [MerchantRewardRuleController::class, 'show']);
        Route::match(['put', 'patch'], 'reward-rules/{rewardRule}', [MerchantRewardRuleController::class, 'update']);
        Route::delete('reward-rules/{rewardRule}', [MerchantRewardRuleController::class, 'destroy']);

        Route::get('booking-policy', [MerchantRestaurantBookingPolicyController::class, 'show']);
        Route::patch('booking-policy', [MerchantRestaurantBookingPolicyController::class, 'update']);

        Route::get('widget-settings', [MerchantRestaurantWidgetSettingsController::class, 'show']);
        Route::patch('widget-settings', [MerchantRestaurantWidgetSettingsController::class, 'update']);

        Route::get('cancellation-policies', [MerchantRestaurantCancellationPolicyController::class, 'index']);
        Route::post('cancellation-policies', [MerchantRestaurantCancellationPolicyController::class, 'store']);
        Route::get('cancellation-policies/{cancellationPolicy}', [MerchantRestaurantCancellationPolicyController::class, 'show']);
        Route::match(['put', 'patch'], 'cancellation-policies/{cancellationPolicy}', [MerchantRestaurantCancellationPolicyController::class, 'update']);
        Route::delete('cancellation-policies/{cancellationPolicy}', [MerchantRestaurantCancellationPolicyController::class, 'destroy']);

        Route::get('guests', [MerchantRestaurantGuestController::class, 'index']);

        Route::post('broadcasts', [MerchantRestaurantBroadcastController::class, 'store']);

        Route::get('guest-communication', [MerchantGuestCommunicationController::class, 'show']);
        Route::patch('guest-communication/automated-messaging', [MerchantGuestCommunicationController::class, 'updateAutomatedMessaging']);
        Route::patch('guest-communication/reservation-messaging', [MerchantGuestCommunicationController::class, 'updateReservationMessaging']);

        Route::prefix('onboarding')->group(function (): void {
            Route::patch('contact-cuisine-price', [MerchantRestaurantOnboardingController::class, 'updateContactCuisinePrice']);
            Route::post('profile-photo', [MerchantRestaurantOnboardingController::class, 'uploadProfilePhoto']);
            Route::patch('description', [MerchantRestaurantOnboardingController::class, 'updateDescription']);
            Route::patch('internal-notes', [MerchantRestaurantOnboardingController::class, 'updateInternalNotes']);
            Route::get('internal-notes', [MerchantRestaurantInternalNoteController::class, 'index']);
            Route::post('internal-notes', [MerchantRestaurantInternalNoteController::class, 'store']);
            Route::patch('internal-notes/{internalNote}', [MerchantRestaurantInternalNoteController::class, 'update']);
            Route::delete('internal-notes/{internalNote}', [MerchantRestaurantInternalNoteController::class, 'destroy']);
            Route::put('business-hours', [MerchantRestaurantOnboardingController::class, 'updateBusinessHours']);
            Route::get('meal-types', [MerchantRestaurantOnboardingController::class, 'indexMealTypes']);
            Route::post('meal-types', [MerchantRestaurantOnboardingController::class, 'storeMealType']);
            Route::patch('meal-types/{availabilityPeriod}', [MerchantRestaurantOnboardingController::class, 'updateMealType']);
            Route::delete('meal-types/{availabilityPeriod}', [MerchantRestaurantOnboardingController::class, 'destroyMealType']);
            Route::post('contact-email/send-code', [MerchantRestaurantOnboardingController::class, 'sendEmailVerificationCode']);
            Route::post('contact-email/verify', [MerchantRestaurantOnboardingController::class, 'verifyEmail']);
            Route::get('data', [MerchantRestaurantOnboardingController::class, 'showData']);
            Route::get('status', [MerchantRestaurantOnboardingController::class, 'showStatus']);
            Route::patch('status', [MerchantRestaurantOnboardingController::class, 'updateStatus']);
        });
        Route::get('access-configs', [MerchantAccessConfigController::class, 'index']);
        Route::post('access-configs', [MerchantAccessConfigController::class, 'store']);
        Route::get('access-configs/permissions', [MerchantAccessConfigController::class, 'permissions']);
        Route::get('access-configs/{accessConfig}', [MerchantAccessConfigController::class, 'show']);
        Route::patch('access-configs/{accessConfig}', [MerchantAccessConfigController::class, 'update']);
        Route::delete('access-configs/{accessConfig}', [MerchantAccessConfigController::class, 'destroy']);

        Route::get('staff', [MerchantRestaurantStaffController::class, 'index']);
        Route::post('staff', [MerchantRestaurantStaffController::class, 'store']);
        Route::patch('staff/{user}', [MerchantRestaurantStaffController::class, 'update']);
        Route::delete('staff/{user}', [MerchantRestaurantStaffController::class, 'destroy']);

        Route::get('servers', [MerchantRestaurantServerController::class, 'index']);
        Route::post('servers', [MerchantRestaurantServerController::class, 'store']);

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
        Route::put('dining-areas/{diningArea}/layout', [MerchantDiningAreaController::class, 'syncLayout']);

        Route::get('dining-areas/{diningArea}/spots', [MerchantDiningSpotController::class, 'index']);
        Route::post('dining-areas/{diningArea}/spots', [MerchantDiningSpotController::class, 'store']);
        Route::patch('dining-areas/{diningArea}/spots/{spot}', [MerchantDiningSpotController::class, 'update']);
        Route::delete('dining-areas/{diningArea}/spots/{spot}', [MerchantDiningSpotController::class, 'destroy']);

        Route::get('tables', [MerchantTableController::class, 'index']);
        Route::post('tables', [MerchantTableController::class, 'store']);
        Route::patch('tables/{table}', [MerchantTableController::class, 'update']);
        Route::patch('tables/{table}/status', [MerchantTableController::class, 'updateStatus']);
        Route::patch('tables/{table}/assign-server', [MerchantTableController::class, 'assignServer']);
        Route::get('tables/available-servers', [MerchantTableController::class, 'availableServers']);
        Route::delete('tables/{table}', [MerchantTableController::class, 'destroy']);

        Route::get('menu-categories', [MerchantMenuCategoryController::class, 'index']);
        Route::post('menu-categories', [MerchantMenuCategoryController::class, 'store']);
        Route::patch('menu-categories/{menuCategory}', [MerchantMenuCategoryController::class, 'update']);
        Route::delete('menu-categories/{menuCategory}', [MerchantMenuCategoryController::class, 'destroy']);

        Route::get('menu-items', [MerchantMenuItemController::class, 'index']);
        Route::post('menu-items', [MerchantMenuItemController::class, 'store']);
        Route::get('menu-items/grouped', [MerchantMenuItemController::class, 'grouped']);
        Route::get('menu-items/{menuItem}', [MerchantMenuItemController::class, 'show']);
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
        Route::patch('reservations/{reservation}/service-stage', [MerchantReservationController::class, 'updateServiceStage']);
        Route::post('reservations/{reservation}/cancel', [MerchantReservationController::class, 'cancel']);

        Route::get('reviews/aggregate', [MerchantRestaurantReviewController::class, 'aggregate']);
        Route::get('reviews', [MerchantRestaurantReviewController::class, 'index']);

        Route::get('waitlist-entries', [MerchantWaitlistController::class, 'index']);
        Route::post('waitlist-entries', [MerchantWaitlistController::class, 'store']);
        Route::get('waitlist-entries/{waitlistEntry}', [MerchantWaitlistController::class, 'show']);
        Route::post('waitlist-entries/{waitlistEntry}/notify', [MerchantWaitlistController::class, 'notify']);
        Route::post('waitlist-entries/{waitlistEntry}/arrive', [MerchantWaitlistController::class, 'arrive']);
        Route::post('waitlist-entries/{waitlistEntry}/partially-arrive', [MerchantWaitlistController::class, 'partiallyArrive']);
        Route::post('waitlist-entries/{waitlistEntry}/assign-table', [MerchantWaitlistController::class, 'assignTable']);
        Route::post('waitlist-entries/{waitlistEntry}/preassign-table', [MerchantWaitlistController::class, 'preassignTable']);
        Route::post('waitlist-entries/{waitlistEntry}/cancel', [MerchantWaitlistController::class, 'cancel']);

        // Status transitions
        Route::post('reservations/{reservation}/arrive', [MerchantReservationController::class, 'arrive']);
        Route::post('reservations/{reservation}/partially-arrive', [MerchantReservationController::class, 'partiallyArrive']);
        Route::post('reservations/{reservation}/left-message', [MerchantReservationController::class, 'leftMessage']);
        Route::post('reservations/{reservation}/running-late', [MerchantReservationController::class, 'runningLate']);
        Route::post('reservations/{reservation}/no-show', [MerchantReservationController::class, 'noShow']);

        // Guestbook
        Route::prefix('guestbook')->group(function (): void {
            Route::get('/', [GuestbookController::class, 'index']);
            Route::post('/', [GuestbookController::class, 'store']);
            Route::get('{guestContact}', [GuestbookController::class, 'show']);
            Route::patch('{guestContact}', [GuestbookController::class, 'update']);
            Route::delete('{guestContact}', [GuestbookController::class, 'destroy']);
            Route::get('{guestContact}/preferences', [GuestbookController::class, 'showPreferences']);
            Route::patch('{guestContact}/preferences', [GuestbookController::class, 'updatePreferences']);
            Route::get('{guestContact}/history', [GuestbookController::class, 'history']);
        });

        // Table Combinations
        Route::get('table-combinations', [MerchantTableCombinationController::class, 'index']);
        Route::post('table-combinations', [MerchantTableCombinationController::class, 'store']);
        Route::patch('table-combinations/{combination}', [MerchantTableCombinationController::class, 'update']);
        Route::delete('table-combinations/{combination}', [MerchantTableCombinationController::class, 'destroy']);

        // Front of House
        Route::prefix('front-of-house')->group(function (): void {
            Route::get('service-periods', [FrontOfHouseOperationsController::class, 'servicePeriods']);
            Route::get('available-tables', [FrontOfHouseOperationsController::class, 'availableTables']);
            Route::get('removed', [FrontOfHouseOperationsController::class, 'removed']);
            Route::get('shift-notes', [FrontOfHouseShiftNoteController::class, 'index']);
            Route::post('shift-notes', [FrontOfHouseShiftNoteController::class, 'store']);
            Route::patch('shift-notes/{shiftNote}', [FrontOfHouseShiftNoteController::class, 'update']);
            Route::delete('shift-notes/{shiftNote}', [FrontOfHouseShiftNoteController::class, 'destroy']);
            Route::get('summary', [FrontOfHouseController::class, 'summary']);
            Route::get('reservations', [FrontOfHouseController::class, 'reservations']);
            Route::get('arrived', [FrontOfHouseController::class, 'arrived']);
            Route::get('waitlist', [FrontOfHouseController::class, 'waitlist']);
            Route::get('availability-alerts', [FrontOfHouseController::class, 'availabilityAlerts']);
            Route::get('seated', [FrontOfHouseController::class, 'seated']);
            Route::get('finished', [FrontOfHouseController::class, 'finished']);
            Route::get('no-show', [FrontOfHouseController::class, 'noshow']);

            Route::get('floors', [FrontOfHouseFloorPlanController::class, 'index']);
            Route::get('floors/{diningArea}', [FrontOfHouseFloorPlanController::class, 'show']);

            Route::get('timelines/active-floors', [FrontOfHouseTimelineController::class, 'activeFloors']);
            Route::get('timelines', [FrontOfHouseTimelineController::class, 'index']);

            // Shift Overview
            Route::get('shift-overview/cover-count', [FrontOfHouseShiftOverviewController::class, 'coverCountReport']);
            Route::get('shift-overview/covers-by-time', [FrontOfHouseShiftOverviewController::class, 'coversByTime']);
            Route::get('shift-overview/covers-by-source', [FrontOfHouseShiftOverviewController::class, 'coversBySource']);
            Route::get('shift-overview/covers-by-party-size', [FrontOfHouseShiftOverviewController::class, 'coversByPartySize']);
        });

        Route::prefix('reporting')->group(function (): void {
            Route::get('filters', [MerchantReportingController::class, 'filters']);
            Route::get('shift-occupancy', [MerchantReportingController::class, 'shiftOccupancy']);
            Route::get('cover-trends', [MerchantReportingController::class, 'coverTrends']);
            Route::get('first-time-visits', [MerchantReportingController::class, 'firstTimeVisits']);
            Route::get('guest-frequency', [MerchantReportingController::class, 'guestFrequency']);
            Route::get('guest-frequency/export', [MerchantReportingController::class, 'exportGuestFrequency']);
            Route::get('reservations', [MerchantReportingController::class, 'reservations']);
            Route::get('reservations/export', [MerchantReportingController::class, 'exportReservations']);
            Route::get('turn-times', [MerchantReportingController::class, 'turnTimes']);
            Route::get('guest-export', [MerchantReportingController::class, 'guestExport']);
            Route::get('guest-export/export', [MerchantReportingController::class, 'exportGuestExport']);
        });
    });
});

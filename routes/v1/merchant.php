<?php

use App\Http\Controllers\Api\V1\MerchantDiningAreaController;
use App\Http\Controllers\Api\V1\MerchantReservationController;
use App\Http\Controllers\Api\V1\MerchantRestaurantController;
use App\Http\Controllers\Api\V1\MerchantTableController;
use App\Http\Controllers\Api\V1\MerchantWaitlistController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('merchant/restaurants/{restaurant}')->group(function (): void {
    Route::get('/', [MerchantRestaurantController::class, 'show']);
    Route::patch('/', [MerchantRestaurantController::class, 'update']);

    Route::get('dining-areas', [MerchantDiningAreaController::class, 'index']);
    Route::post('dining-areas', [MerchantDiningAreaController::class, 'store']);
    Route::patch('dining-areas/{diningArea}', [MerchantDiningAreaController::class, 'update']);
    Route::delete('dining-areas/{diningArea}', [MerchantDiningAreaController::class, 'destroy']);

    Route::get('tables', [MerchantTableController::class, 'index']);
    Route::post('tables', [MerchantTableController::class, 'store']);
    Route::patch('tables/{table}', [MerchantTableController::class, 'update']);
    Route::patch('tables/{table}/status', [MerchantTableController::class, 'updateStatus']);
    Route::delete('tables/{table}', [MerchantTableController::class, 'destroy']);

    Route::get('reservations', [MerchantReservationController::class, 'index']);
    Route::post('reservations', [MerchantReservationController::class, 'store']);
    Route::get('reservations/{reservation}', [MerchantReservationController::class, 'show']);
    Route::patch('reservations/{reservation}', [MerchantReservationController::class, 'update']);
    Route::post('reservations/{reservation}/assign-table', [MerchantReservationController::class, 'assignTable']);
    Route::post('reservations/{reservation}/seat', [MerchantReservationController::class, 'seat']);
    Route::post('reservations/{reservation}/complete', [MerchantReservationController::class, 'complete']);
    Route::post('reservations/{reservation}/cancel', [MerchantReservationController::class, 'cancel']);

    Route::get('waitlist-entries', [MerchantWaitlistController::class, 'index']);
    Route::post('waitlist-entries', [MerchantWaitlistController::class, 'store']);
    Route::post('waitlist-entries/{waitlistEntry}/notify', [MerchantWaitlistController::class, 'notify']);
    Route::post('waitlist-entries/{waitlistEntry}/assign-table', [MerchantWaitlistController::class, 'assignTable']);
});

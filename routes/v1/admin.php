<?php

use App\Http\Controllers\Api\V1\AdminAuditLogController;
use App\Http\Controllers\Api\V1\AdminAuthController;
use App\Http\Controllers\Api\V1\AdminOrganizationController;
use App\Http\Controllers\Api\V1\AdminRestaurantController;
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
    Route::get('organizations', [AdminOrganizationController::class, 'index']);
    Route::post('organizations', [AdminOrganizationController::class, 'store']);
    Route::get('organizations/{organization}', [AdminOrganizationController::class, 'show']);
    Route::patch('organizations/{organization}', [AdminOrganizationController::class, 'update']);

    Route::get('restaurants', [AdminRestaurantController::class, 'index']);
    Route::post('restaurants', [AdminRestaurantController::class, 'store']);
    Route::get('restaurants/{restaurant}', [AdminRestaurantController::class, 'show']);
    Route::patch('restaurants/{restaurant}', [AdminRestaurantController::class, 'update']);
    Route::post('restaurants/{restaurant}/invite-owner', [AdminRestaurantController::class, 'inviteOwner']);
    Route::patch('restaurants/{restaurant}/status', [AdminRestaurantController::class, 'updateStatus']);

    Route::put('users/{user}/roles', [AdminUserRoleController::class, 'update']);

    Route::get('audit-logs', [AdminAuditLogController::class, 'index']);
});

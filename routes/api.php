<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    require __DIR__.'/v1/auth.php';
    require __DIR__.'/v1/public.php';
    require __DIR__.'/v1/merchant.php';
    require __DIR__.'/v1/admin.php';
});

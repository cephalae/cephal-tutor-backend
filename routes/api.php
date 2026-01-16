<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Provider\ProviderAuthController;
use App\Http\Controllers\Admin\ProviderController;
use App\Http\Controllers\Admin\ProviderUserController;


// -------- Admin Auth --------
Route::prefix('admin')->group(function () {
    Route::post('auth/login', [AdminAuthController::class, 'login']);

    Route::middleware('auth:admin_api')->group(function () {
        Route::post('auth/logout', [AdminAuthController::class, 'logout']);
        Route::post('auth/refresh', [AdminAuthController::class, 'refresh']);
        Route::get('auth/me', [AdminAuthController::class, 'me']);

        // Providers CRUD
        Route::apiResource('providers', ProviderController::class);

        // Provider users under a provider
        Route::get('providers/{provider}/users', [ProviderUserController::class, 'index']);
        Route::post('providers/{provider}/users', [ProviderUserController::class, 'store']);
        Route::get('providers/{provider}/users/{user}', [ProviderUserController::class, 'show']);
        Route::put('providers/{provider}/users/{user}', [ProviderUserController::class, 'update']);
        Route::delete('providers/{provider}/users/{user}', [ProviderUserController::class, 'destroy']);
    });
});

// -------- Provider Auth --------
Route::prefix('provider')->group(function () {
    Route::post('auth/login', [ProviderAuthController::class, 'login']);

    Route::middleware('auth:provider_api')->group(function () {
        Route::post('auth/logout', [ProviderAuthController::class, 'logout']);
        Route::post('auth/refresh', [ProviderAuthController::class, 'refresh']);
        Route::get('auth/me', [ProviderAuthController::class, 'me']);
    });
});
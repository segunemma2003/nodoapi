<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\BusinessController;

// Authentication routes
Route::prefix('auth')->group(function () {
    Route::post('admin/login', [AuthController::class, 'adminLogin']);
    Route::post('business/login', [AuthController::class, 'businessLogin']);

    // Protected auth routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Admin routes
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('dashboard', [AdminController::class, 'dashboard']);
    Route::post('businesses', [AdminController::class, 'createBusiness']);
});

// Business routes
Route::prefix('business')->middleware(['auth:sanctum', 'business'])->group(function () {
    Route::get('dashboard', [BusinessController::class, 'dashboard']);
    Route::post('vendors', [BusinessController::class, 'createVendor']);
    Route::post('purchase-orders', [BusinessController::class, 'createPurchaseOrder']);
});

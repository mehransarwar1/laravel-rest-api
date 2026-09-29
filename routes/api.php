<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductVariantController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:register')
            ->name('auth.register');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('auth.login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])
                ->name('auth.logout');
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [AuthController::class, 'me'])
            ->name('auth.me');
    });

    Route::get('categories', [CategoryController::class, 'index'])
        ->name('categories.index');
    Route::get('categories/{category}', [CategoryController::class, 'show'])
        ->name('categories.show');

    Route::middleware(['auth:sanctum', 'throttle:api-writes'])->group(function (): void {
        Route::post('categories', [CategoryController::class, 'store'])
            ->name('categories.store');
        Route::put('categories/{category}', [CategoryController::class, 'update'])
            ->name('categories.update');
        Route::patch('categories/{category}', [CategoryController::class, 'update']);
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])
            ->name('categories.destroy');
    });

    Route::get('products', [ProductController::class, 'index'])
        ->name('products.index');
    Route::get('products/{product}', [ProductController::class, 'show'])
        ->name('products.show');

    Route::middleware(['auth:sanctum', 'throttle:api-writes'])->group(function (): void {
        Route::post('products', [ProductController::class, 'store'])
            ->name('products.store');
        Route::put('products/{product}', [ProductController::class, 'update'])
            ->name('products.update');
        Route::patch('products/{product}', [ProductController::class, 'update']);
        Route::delete('products/{product}', [ProductController::class, 'destroy'])
            ->name('products.destroy');
    });

    Route::scopeBindings()->group(function (): void {
        Route::get('products/{product}/variants', [ProductVariantController::class, 'index'])
            ->name('products.variants.index');
        Route::get('products/{product}/variants/{variant}', [ProductVariantController::class, 'show'])
            ->name('products.variants.show');

        Route::middleware(['auth:sanctum', 'throttle:api-writes'])->group(function (): void {
            Route::post('products/{product}/variants', [ProductVariantController::class, 'store'])
                ->name('products.variants.store');
            Route::put('products/{product}/variants/{variant}', [ProductVariantController::class, 'update'])
                ->name('products.variants.update');
            Route::patch('products/{product}/variants/{variant}', [ProductVariantController::class, 'update']);
            Route::delete('products/{product}/variants/{variant}', [ProductVariantController::class, 'destroy'])
                ->name('products.variants.destroy');
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('orders', [OrderController::class, 'index'])
            ->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])
            ->name('orders.show');
    });

    Route::middleware(['auth:sanctum', 'throttle:api-writes'])->group(function (): void {
        Route::post('orders', [OrderController::class, 'store'])
            ->name('orders.store');
        Route::patch('orders/{order}', [OrderController::class, 'update'])
            ->name('orders.update');
        Route::delete('orders/{order}', [OrderController::class, 'destroy'])
            ->name('orders.destroy');
    });
});

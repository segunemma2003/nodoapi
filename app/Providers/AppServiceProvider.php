<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\Models\PurchaseOrder;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Custom route model binding for PurchaseOrder
        Route::bind('po', function ($value) {
            // If the value looks like a PO number (starts with PO), search by po_number
            if (is_string($value) && str_starts_with($value, 'PO')) {
                return PurchaseOrder::where('po_number', $value)->firstOrFail();
            }

            // Otherwise, search by ID
            return PurchaseOrder::where('id', $value)->firstOrFail();
        });
    }
}

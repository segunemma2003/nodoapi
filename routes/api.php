<?php

// Enhanced routes/api.php with Interest Rate Frequency Management

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\AdminInterestRateController;

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
    // Dashboard and basic admin functions
    Route::get('dashboard', [AdminController::class, 'dashboard']);
    Route::post('businesses', [AdminController::class, 'createBusiness']);

    // Payment management
    Route::get('payments/pending', [AdminController::class, 'getPendingPayments']);
    Route::post('payments/{payment}/approve', [AdminController::class, 'approvePayment']);
    Route::post('payments/{payment}/reject', [AdminController::class, 'rejectPayment']);

    // Business credit management
    Route::get('businesses', [AdminController::class, 'getBusinesses']);
    Route::get('businesses/{business}', [AdminController::class, 'getBusinessDetails']);
    Route::post('businesses/{business}/adjust-credit', [AdminController::class, 'adjustAssignedCredit']);

    // Interest application
    Route::post('businesses/{business}/apply-interest', [AdminController::class, 'applyInterest']);
    Route::post('bulk-interest-application', [AdminController::class, 'bulkApplyInterest']);

    // Treasury management
    Route::post('businesses/{business}/treasury/update', [AdminController::class, 'updateTreasury']);

    // ENHANCED INTEREST RATE FREQUENCY MANAGEMENT
    Route::prefix('interest-rates')->group(function () {
        // Basic interest rate management
        Route::get('/', [AdminInterestRateController::class, 'getInterestRates']);
        Route::post('update', [AdminInterestRateController::class, 'updateInterestRates']);

        // FREQUENCY-SPECIFIC ENDPOINTS
        Route::get('frequencies', [AdminInterestRateController::class, 'getAvailableFrequencies']);
        Route::get('comparison', [AdminInterestRateController::class, 'getInterestRateComparison']);
        Route::post('update-with-frequency', [AdminInterestRateController::class, 'updateInterestRateWithFrequency']);

        // Bulk operations by frequency
        Route::post('bulk-apply/{frequency}', [AdminInterestRateController::class, 'bulkApplyInterestByFrequency']);
        Route::get('status/{frequency}', [AdminInterestRateController::class, 'getFrequencyStatus']);

        // Interest rate history and analytics
        Route::get('history', [AdminInterestRateController::class, 'getRateHistory']);
        Route::get('history/{rateType}', [AdminInterestRateController::class, 'getSpecificRateHistory']);
        Route::post('impact-analysis', [AdminInterestRateController::class, 'analyzeRateImpact']);

        // Schedule management
        Route::get('schedule/status', [AdminInterestRateController::class, 'getScheduleStatus']);
        Route::post('schedule/run/{frequency}', [AdminInterestRateController::class, 'runScheduledInterest']);
    });

    // Risk Tier Management with Frequencies
    Route::prefix('risk-tiers')->group(function () {
        Route::get('/', [AdminInterestRateController::class, 'getRiskTiers']);
        Route::post('/', [AdminInterestRateController::class, 'createRiskTier']);
        Route::put('{tier}', [AdminInterestRateController::class, 'updateRiskTier']);
        Route::delete('{tier}', [AdminInterestRateController::class, 'deleteRiskTier']);

        // Frequency-specific tier operations
        Route::post('{tier}/set-frequency', [AdminInterestRateController::class, 'setTierFrequency']);
        Route::get('by-frequency/{frequency}', [AdminInterestRateController::class, 'getTiersByFrequency']);
    });

    // Business-specific interest rate management
    Route::prefix('businesses/{business}')->group(function () {
        Route::post('set-interest-rate', [AdminInterestRateController::class, 'setBusinessInterestRate']);
        Route::post('assign-tier', [AdminInterestRateController::class, 'assignTierToBusiness']);
        Route::get('interest-history', [AdminInterestRateController::class, 'getBusinessInterestHistory']);
        Route::post('calculate-interest', [AdminInterestRateController::class, 'calculateBusinessInterest']);
    });

    // Bulk tier assignment
    Route::post('businesses/bulk-tier-assignment', [AdminInterestRateController::class, 'bulkAssignTiers']);

    // System settings
    Route::get('system-settings', [AdminController::class, 'getSystemSettings']);
    Route::post('system-settings', [AdminController::class, 'updateSystemSettings']);

    // Platform analytics
    Route::get('platform-summary', [AdminController::class, 'getPlatformSummary']);
    Route::get('reports/portfolio-performance', [AdminController::class, 'getPortfolioPerformance']);
});

// Business routes
Route::prefix('business')->middleware(['auth:sanctum', 'business'])->group(function () {
    // Dashboard and basic info
    Route::get('dashboard', [BusinessController::class, 'dashboard']);
    Route::get('credit-status', [BusinessController::class, 'getCreditStatus']);
    Route::get('spending-analysis', [BusinessController::class, 'getSpendingAnalysis']);
    Route::get('outstanding-debt', [BusinessController::class, 'getOutstandingDebt']);

    // ENHANCED INTEREST RATE INFORMATION FOR BUSINESS
    Route::prefix('interest')->group(function () {
        Route::get('current-rate', [BusinessController::class, 'getCurrentInterestRate']);
        Route::get('calculation-preview', [BusinessController::class, 'getInterestCalculationPreview']);
        Route::get('payment-projections', [BusinessController::class, 'getPaymentProjections']);
        Route::get('frequency-impact', [BusinessController::class, 'getFrequencyImpactAnalysis']);
    });

    // Vendor management
    Route::get('vendors', [BusinessController::class, 'getVendors']);
    Route::post('vendors', [BusinessController::class, 'createVendor']);

    // Purchase order management
    Route::get('purchase-orders', [BusinessController::class, 'getPurchaseOrders']);
    Route::post('purchase-orders', [BusinessController::class, 'createPurchaseOrder']);
    Route::get('purchase-orders/{po}', [BusinessController::class, 'getPurchaseOrder']);

    // Payment management
    Route::post('purchase-orders/{po}/payments', [BusinessController::class, 'submitPayment']);
    Route::get('purchase-orders/{po}/payments', [BusinessController::class, 'getPaymentHistory']);
    Route::get('payments/pending', [BusinessController::class, 'getPendingPayments']);

    // Spending optimization
    Route::get('spending-suggestions', [BusinessController::class, 'getSpendingSuggestions']);
});

// PUBLIC API ENDPOINTS (for integrations)
Route::prefix('public')->group(function () {
    // System health and status
    Route::get('health', function () {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now(),
            'system' => 'B2B Revolving Credit Platform',
            'version' => '2.0.0'
        ]);
    });

    // Interest rate information (public)
    Route::get('interest-rates/frequencies', function () {
        return response()->json([
            'success' => true,
            'data' => \App\Models\SystemSetting::getAvailableFrequencies()
        ]);
    });

    // API documentation
    Route::get('docs', function () {
        return response()->json([
            'message' => 'B2B Revolving Credit Platform API Documentation',
            'features' => [
                'Multi-frequency interest rates (daily, weekly, monthly, quarterly, annual)',
                'Flexible payment terms and risk tiers',
                'Real-time balance management',
                'Automated interest accrual',
                'Comprehensive audit trails'
            ],
            'endpoints' => [
                'swagger_ui' => url('/api/documentation'),
                'postman_collection' => url('/api/postman'),
            ],
        ]);
    });


});

// FILE DOWNLOAD ROUTES (protected)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('payments/{payment}/receipt', [BusinessController::class, 'downloadReceipt'])->name('payments.receipt');
    Route::get('download/purchase-order/{po}/pdf', [BusinessController::class, 'downloadPurchaseOrderPdf']);
    Route::get('download/interest-report/{business}', [AdminController::class, 'downloadInterestReport']);
});

// ADMINISTRATIVE MONITORING ROUTES
Route::prefix('monitor')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('interest-schedule', function () {
        $schedules = [
            'daily' => \App\Models\Business::whereHas('riskTier', function($q) {
                $q->where('interest_frequency', 'daily');
            })->count(),
            'weekly' => \App\Models\Business::whereHas('riskTier', function($q) {
                $q->where('interest_frequency', 'weekly');
            })->count(),
            'monthly' => \App\Models\Business::whereHas('riskTier', function($q) {
                $q->where('interest_frequency', 'monthly');
            })->count(),
            'quarterly' => \App\Models\Business::whereHas('riskTier', function($q) {
                $q->where('interest_frequency', 'quarterly');
            })->count(),
            'annual' => \App\Models\Business::whereHas('riskTier', function($q) {
                $q->where('interest_frequency', 'annual');
            })->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'schedule_distribution' => $schedules,
                'total_businesses' => array_sum($schedules),
                'next_runs' => [
                    'daily' => now()->addDay()->setTime(0, 5)->format('Y-m-d H:i'),
                    'weekly' => now()->startOfWeek()->addWeek()->setTime(0, 10)->format('Y-m-d H:i'),
                    'monthly' => now()->startOfMonth()->addMonth()->setTime(0, 15)->format('Y-m-d H:i'),
                ]
            ]
        ]);
    });

    Route::get('system/balance-summary', function () {
        $businesses = \App\Models\Business::with('riskTier')->get();

        $frequencyBreakdown = [];
        foreach (['daily', 'weekly', 'monthly', 'quarterly', 'annual'] as $freq) {
            $businesses_with_freq = $businesses->filter(function($b) use ($freq) {
                $config = $b->getInterestRateConfig();
                return $config['frequency'] === $freq;
            });

            $frequencyBreakdown[$freq] = [
                'count' => $businesses_with_freq->count(),
                'total_debt' => $businesses_with_freq->sum('credit_balance'),
                'avg_rate' => $businesses_with_freq->avg(function($b) {
                    return $b->getInterestRateConfig()['rate'];
                })
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'frequency_breakdown' => $frequencyBreakdown,
                'system_totals' => [
                    'total_businesses' => $businesses->count(),
                    'total_debt' => $businesses->sum('credit_balance'),
                    'total_assigned_credit' => $businesses->sum('current_balance'),
                ]
            ]
        ]);
    });
});

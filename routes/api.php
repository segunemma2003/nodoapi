<?php

// Updated routes/api.php with missing endpoints

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\AdminInterestRateController;
use App\Http\Controllers\Api\AdminSupportController;
use App\Http\Controllers\Api\BusinessSupportController;
use Illuminate\Http\Request;

// Authentication routes
Route::prefix('auth')->group(function () {
    Route::post('admin/login', [AuthController::class, 'adminLogin']);
    Route::post('business/login', [AuthController::class, 'businessLogin']);

    // Protected auth routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        // PASSWORD CHANGE (NEW) - Works for both admin and business
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });
});

// Admin routes
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // Dashboard and basic admin functions
    Route::get('dashboard', [AdminController::class, 'dashboard']);
    Route::post('businesses', [AdminController::class, 'createBusiness']);

    // ADMIN PROFILE MANAGEMENT (NEW)
    Route::get('profile', [AdminController::class, 'getAdminProfile']);
    Route::put('profile', [AdminController::class, 'updateAdminProfile']);

    // Payment management
    Route::get('payments/pending', [AdminController::class, 'getPendingPayments']);
    Route::post('payments/{payment}/approve', [AdminController::class, 'approvePayment']);
    Route::post('payments/{payment}/reject', [AdminController::class, 'rejectPayment']);

    // Business credit management
    Route::get('businesses', [AdminController::class, 'getBusinessesEnhanced']);
    Route::get('businesses/{business}', [AdminController::class, 'getBusinessDetails']);
    Route::post('businesses/{business}/adjust-credit', [AdminController::class, 'adjustAssignedCredit']);

    // VENDOR MANAGEMENT
    Route::prefix('vendors')->group(function () {
        Route::get('/', [AdminController::class, 'getVendors']);
        Route::get('{vendor}', [AdminController::class, 'getVendorDetails']);
        Route::post('{vendor}/approve', [AdminController::class, 'approveVendor']);
        Route::post('{vendor}/reject', [AdminController::class, 'rejectVendor']);
    });

    // PURCHASE ORDERS MANAGEMENT
    Route::prefix('purchase-orders')->group(function () {
        Route::get('/', [AdminController::class, 'getPurchaseOrders']);
        Route::get('{po}', [AdminController::class, 'getPurchaseOrderDetails']);
        Route::post('{po}/approve', [AdminController::class, 'approvePurchaseOrder']);
        Route::post('{po}/reject', [AdminController::class, 'rejectPurchaseOrder']);
        Route::put('{po}/update-status', [AdminController::class, 'updatePurchaseOrderStatus']);
    });

    // USER MANAGEMENT
    Route::prefix('users')->group(function () {
        Route::get('/', [AdminController::class, 'getUsers']);
        Route::post('/', [AdminController::class, 'createUser']);
        Route::put('{user}', [AdminController::class, 'updateUser']);
        Route::delete('{user}', [AdminController::class, 'deleteUser']);
    });

    // PAYMENT HISTORY & TRANSACTIONS
    Route::prefix('payments')->group(function () {
        Route::get('pending', [AdminController::class, 'getPendingPayments']);
        Route::get('history', [AdminController::class, 'getPaymentHistory']);
        Route::post('{payment}/approve', [AdminController::class, 'approvePayment']);
        Route::post('{payment}/reject', [AdminController::class, 'rejectPayment']);
    });

    Route::prefix('transactions')->group(function () {
        Route::get('recent', [AdminController::class, 'getRecentTransactions']);
    });

    // BUSINESS REPAYMENTS
    Route::prefix('businesses/{business}/repayments')->group(function () {
        Route::get('/', [AdminController::class, 'getBusinessRepayments']);
        Route::post('{payment}/approve', [AdminController::class, 'approveBusinessRepayment']);
        Route::post('{payment}/reject', [AdminController::class, 'rejectBusinessRepayment']);
    });

    // SUPPORT TICKET MANAGEMENT
    Route::prefix('support')->group(function () {
        // Ticket management
        Route::get('tickets', [AdminSupportController::class, 'getTickets']);
        Route::get('tickets/{ticket}', [AdminSupportController::class, 'getTicketDetails']);
        Route::post('tickets', [AdminSupportController::class, 'createTicket']);
        Route::put('tickets/{ticket}', [AdminSupportController::class, 'updateTicket']);

        // Ticket actions
        Route::post('tickets/{ticket}/assign', [AdminSupportController::class, 'assignTicket']);
        Route::post('tickets/{ticket}/close', [AdminSupportController::class, 'closeTicket']);
        Route::post('tickets/{ticket}/response', [AdminSupportController::class, 'addResponse']);

        // Support statistics and reporting
        Route::get('stats', [AdminSupportController::class, 'getStats']);

        // Bulk operations
        Route::post('bulk/assign', [AdminSupportController::class, 'bulkAssignTickets']);
        Route::post('bulk/close', [AdminSupportController::class, 'bulkCloseTickets']);
        Route::post('bulk/update-priority', [AdminSupportController::class, 'bulkUpdatePriority']);
    });

    // Interest application
    Route::post('businesses/{business}/apply-interest', [AdminController::class, 'applyInterest']);
    Route::post('bulk-interest-application', [AdminController::class, 'bulkApplyInterest']);

    // Treasury management
    Route::post('businesses/{business}/treasury/update', [AdminController::class, 'updateTreasury']);

    // ENHANCED INTEREST RATE FREQUENCY MANAGEMENT
    Route::prefix('interest-rates')->group(function () {
        Route::get('frequencies', [AdminInterestRateController::class, 'getAvailableFrequencies']);
        Route::get('comparison', [AdminInterestRateController::class, 'getInterestRateComparison']);
        Route::post('update-with-frequency', [AdminInterestRateController::class, 'updateInterestRateWithFrequency']);
        Route::post('bulk-apply/{frequency}', [AdminInterestRateController::class, 'bulkApplyInterestByFrequency']);
    });

    // Risk Tier Management
    Route::prefix('risk-tiers')->group(function () {
        Route::get('/', [AdminInterestRateController::class, 'getRiskTiers']);
        Route::post('/', [AdminInterestRateController::class, 'createRiskTier']);
        Route::put('{tier}', [AdminInterestRateController::class, 'updateRiskTier']);
        Route::delete('{tier}', [AdminInterestRateController::class, 'deleteRiskTier']);
    });

    // Business-specific interest rate management
    Route::prefix('businesses/{business}')->group(function () {
        Route::post('set-interest-rate', [AdminInterestRateController::class, 'setBusinessInterestRate']);
    });

    // System settings
    Route::get('platform-summary', [AdminController::class, 'getPlatformSummary']);

    Route::prefix('system-settings')->group(function () {
        Route::get('/', [AdminInterestRateController::class, 'getSystemSettings']);
        Route::put('/', [AdminInterestRateController::class, 'updateSystemSettings']);
    });



});

// Business routes
Route::prefix('business')->middleware(['auth:sanctum', 'business'])->group(function () {
    // Dashboard and basic info
    Route::get('dashboard', [BusinessController::class, 'dashboard']);
    Route::get('credit-status', [BusinessController::class, 'getCreditStatus']);
    Route::get('spending-analysis', [BusinessController::class, 'getSpendingAnalysis']);

    // BUSINESS PROFILE MANAGEMENT (NEW)
    Route::get('profile', [BusinessController::class, 'getProfile']);
    Route::put('profile', [BusinessController::class, 'updateProfile']);
    Route::post('profile/upload-logo', [BusinessController::class, 'uploadLogo']);

    // PURCHASE TRACKER ANALYTICS (NEW)
    Route::get('purchase-tracker', [BusinessController::class, 'getPurchaseTracker']);
    Route::get('purchase-trends', [BusinessController::class, 'getPurchaseTrends']);

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

    // BUSINESS SUPPORT TICKETS
    Route::prefix('support')->group(function () {
        Route::get('tickets', [BusinessSupportController::class, 'getMyTickets']);
        Route::get('tickets/{ticket}', [BusinessSupportController::class, 'getTicketDetails']);
        Route::post('tickets', [BusinessSupportController::class, 'createTicket']);
        Route::post('tickets/{ticket}/response', [BusinessSupportController::class, 'addResponse']);
        Route::post('tickets/{ticket}/close', [BusinessSupportController::class, 'closeTicket']);
    });

    // Spending optimization
    Route::get('spending-suggestions', [BusinessController::class, 'getSpendingSuggestions']);
});

// PUBLIC API ENDPOINTS
Route::prefix('public')->group(function () {
    Route::get('health', function () {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now(),
            'system' => 'B2B Revolving Credit Platform',
            'version' => '2.0.0'
        ]);
    });
});

// FILE DOWNLOAD ROUTES (protected)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('payments/{payment}/receipt', [BusinessController::class, 'downloadReceipt'])->name('payments.receipt');
});


Route::get('/test', function () {
    return response()->json([
        'success' => true,
        'message' => 'API is working!',
        'timestamp' => now(),
        'app_url' => config('app.url')
    ]);
});



Route::prefix('webhooks')->group(function () {
    Route::post('paystack', [App\Http\Controllers\Api\PaystackWebhookController::class, 'handleWebhook']);
});

Route::prefix('banks')->group(function () {
    Route::get('/', function () {
        $paystackService = app(App\Services\PaystackService::class);
        return $paystackService->listBanks();
    });

    Route::post('verify-account', function (Request $request) {
        $request->validate([
            'account_number' => 'required|string|size:10',
            'bank_code' => 'required|string|size:3'
        ]);

        $paystackService = app(App\Services\PaystackService::class);
        return $paystackService->verifyAccountNumber(
            $request->account_number,
            $request->bank_code
        );
    });
});

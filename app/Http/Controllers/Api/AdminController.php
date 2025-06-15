<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Business;
use App\Models\PurchaseOrder;
use App\Models\Payment;
use App\Models\Vendor;
use App\Models\SystemSetting;
use App\Mail\BusinessCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *     name="Admin",
 *     description="Admin operations - Credit & Payment Management"
 * )
 */
class AdminController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/admin/dashboard",
     *     summary="Get admin dashboard with platform overview",
     *     tags={"Admin"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Dashboard data retrieved")
     * )
     */
    public function dashboard()
    {
        $businesses = Business::with('riskTier')->get();
        $purchaseOrders = PurchaseOrder::with('business')->get();
        $payments = Payment::with('business')->get();

        $data = [
            'platform_overview' => [
                'total_businesses' => $businesses->count(),
                'active_businesses' => $businesses->where('is_active', true)->count(),
                'total_assigned_credit' => $businesses->sum('current_balance'),
                'total_outstanding_debt' => $businesses->sum('credit_balance'),
                'total_available_spending_power' => $businesses->sum('available_balance'),
                'platform_treasury' => $businesses->sum('treasury_collateral_balance'),
                'credit_utilization_avg' => $businesses->avg(function($b) { return $b->getCreditUtilization(); }),
            ],
            'purchase_order_stats' => [
                'total_purchase_orders' => $purchaseOrders->count(),
                'draft_purchase_orders' => $purchaseOrders->where('status', 'draft')->count(),
                'pending_purchase_orders' => $purchaseOrders->where('status', 'pending')->count(),
                'approved_purchase_orders' => $purchaseOrders->where('status', 'approved')->count(),
                'total_po_value' => $purchaseOrders->sum('net_amount'),
                'unpaid_po_value' => $purchaseOrders->where('payment_status', 'unpaid')->sum('outstanding_amount'),
            ],
            'payment_stats' => [
                'total_payments' => $payments->count(),
                'pending_payments' => $payments->where('status', 'pending')->count(),
                'confirmed_payments' => $payments->where('status', 'confirmed')->count(),
                'rejected_payments' => $payments->where('status', 'rejected')->count(),
                'total_payment_value' => $payments->where('status', 'confirmed')->sum('amount'),
                'pending_payment_value' => $payments->where('status', 'pending')->sum('amount'),
            ],
            'risk_analysis' => [
                'high_utilization_businesses' => $businesses->filter(function($b) {
                    return $b->getCreditUtilization() > 80;
                })->count(),
                'overdue_payments' => PurchaseOrder::overdue()->count(),
                'businesses_with_debt' => $businesses->where('credit_balance', '>', 0)->count(),
            ],
            'recent_activities' => [
                'recent_businesses' => Business::with('createdBy')->latest()->take(5)->get(),
                'recent_purchase_orders' => PurchaseOrder::with(['business', 'vendor'])
                    ->latest()->take(10)->get(),
                'recent_payments' => Payment::with(['business', 'purchaseOrder'])
                    ->latest()->take(10)->get(),
            ],
        ];

        return response()->json([
            'success' => true,
            'message' => 'Admin dashboard data retrieved successfully',
            'data' => $data
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/admin/businesses",
     *     summary="Create business and assign initial credit",
     *     tags={"Admin"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","email","business_type","initial_credit"},
     *             @OA\Property(property="name", type="string", example="Tech Company"),
     *             @OA\Property(property="email", type="string", format="email", example="tech@company.com"),
     *             @OA\Property(property="business_type", type="string", example="Technology"),
     *             @OA\Property(property="initial_credit", type="number", format="float", example=50000)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Business created with assigned credit")
     * )
     */
    public function createBusiness(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:businesses,email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'business_type' => 'required|string|max:100',
            'registration_number' => 'nullable|string|unique:businesses,registration_number',
            'initial_credit' => 'required|numeric|min:1000|max:10000000',
        ]);

        // Generate random password
        $password = Str::random(12);

        DB::beginTransaction();
        try {
            $business = Business::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $request->address,
                'business_type' => $request->business_type,
                'registration_number' => $request->registration_number,
                'password' => $password,
                'created_by' => Auth::id(),
                // Balances will be set by assignInitialCredit method
            ]);

            // Assign initial credit
            $business->assignInitialCredit($request->initial_credit, Auth::id());

            DB::commit();

            // Send credentials email
            try {
                Mail::to($business->email)->send(new BusinessCredentials($business, $password));
            } catch (\Exception $e) {
                Log::error('Failed to send credentials email: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Business created successfully with initial credit assigned',
                'data' => [
                    'business' => $business->load('createdBy'),
                    'credit_summary' => [
                        'initial_credit_assigned' => $request->initial_credit,
                        'available_spending_power' => $business->available_balance,
                        'current_utilization' => 0,
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create business',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/payments/pending",
     *     summary="Get all pending payments awaiting approval",
     *     tags={"Admin"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Pending payments retrieved")
     * )
     */
    public function getPendingPayments()
    {
        $pendingPayments = Payment::where('status', 'pending')
            ->with(['business', 'purchaseOrder.vendor'])
            ->orderBy('created_at', 'asc') // Oldest first for processing
            ->get();

        $totalPendingValue = $pendingPayments->sum('amount');
        $businessesWithPendingPayments = $pendingPayments->groupBy('business_id')->count();

        return response()->json([
            'success' => true,
            'message' => 'Pending payments retrieved successfully',
            'data' => [
                'pending_payments' => $pendingPayments,
                'summary' => [
                    'total_pending_payments' => $pendingPayments->count(),
                    'total_pending_value' => $totalPendingValue,
                    'businesses_with_pending' => $businessesWithPendingPayments,
                    'oldest_pending_days' => $pendingPayments->first() ?
                        now()->diffInDays($pendingPayments->first()->created_at) : 0,
                ]
            ]
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/admin/payments/{payment}/approve",
     *     summary="Approve payment and restore business spending power",
     *     tags={"Admin"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="payment",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="notes", type="string", example="Payment verified and approved")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Payment approved - spending power restored")
     * )
     */
    public function approvePayment(Request $request, Payment $payment)
    {
        if ($payment->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Payment is not pending approval'
            ], 400);
        }

        $request->validate([
            'notes' => 'nullable|string|max:500'
        ]);

        DB::beginTransaction();
        try {
            $admin = Auth::user();
            $business = $payment->business;
            $po = $payment->purchaseOrder;

            // Update payment status
            $payment->update([
                'status' => 'confirmed',
                'confirmed_by' => $admin->id,
                'confirmed_at' => now(),
                'notes' => ($payment->notes ?? '') . "\nAdmin notes: " . ($request->notes ?? 'Approved'),
            ]);

            // Update PO payment tracking
            $po->updatePaymentAmounts($payment->amount);

            // RESTORE SPENDING POWER - This is the key operation
            $business->approvePayment($payment->amount, $payment->id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment approved successfully - spending power restored',
                'data' => [
                    'payment' => $payment->fresh(),
                    'business_updated_balances' => [
                        'available_spending_power' => $business->fresh()->available_balance,
                        'outstanding_debt' => $business->fresh()->credit_balance,
                        'credit_utilization' => $business->fresh()->getCreditUtilization(),
                    ],
                    'purchase_order_updated' => [
                        'outstanding_amount' => $po->fresh()->outstanding_amount,
                        'payment_status' => $po->fresh()->payment_status,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/admin/payments/{payment}/reject",
     *     summary="Reject payment submission",
     *     tags={"Admin"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Payment rejected")
     * )
     */
    public function rejectPayment(Request $request, Payment $payment)
    {
        if ($payment->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Payment is not pending approval'
            ], 400);
        }

        $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        DB::beginTransaction();
        try {
            $admin = Auth::user();
            $business = $payment->business;

            // Update payment status
            $payment->update([
                'status' => 'rejected',
                'rejected_by' => $admin->id,
                'rejected_at' => now(),
                'rejection_reason' => $request->reason,
            ]);

            // Log the rejection (no balance changes)
            $business->rejectPayment($payment->amount, $payment->id, $request->reason);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment rejected successfully',
                'data' => [
                    'payment' => $payment->fresh(),
                    'rejection_reason' => $request->reason,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/admin/businesses/{business}/adjust-credit",
     *     summary="Adjust business assigned credit limit",
     *     tags={"Admin"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Credit limit adjusted")
     * )
     */
    public function adjustAssignedCredit(Request $request, Business $business)
    {
        $request->validate([
            'new_credit_amount' => 'required|numeric|min:0|max:50000000',
            'reason' => 'required|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $oldCredit = $business->getTotalAssignedCredit();
            $newCredit = $request->new_credit_amount;
            $admin = Auth::user();

            // Adjust assigned credit
            $business->adjustAssignedCredit($newCredit, $request->reason, $admin->id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Business credit limit adjusted successfully',
                'data' => [
                    'business' => $business->fresh(),
                    'credit_adjustment' => [
                        'previous_credit' => $oldCredit,
                        'new_credit' => $newCredit,
                        'difference' => $newCredit - $oldCredit,
                        'reason' => $request->reason,
                    ],
                    'updated_balances' => [
                        'total_assigned_credit' => $business->fresh()->current_balance,
                        'available_spending_power' => $business->fresh()->available_balance,
                        'outstanding_debt' => $business->fresh()->credit_balance,
                        'credit_utilization' => $business->fresh()->getCreditUtilization(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to adjust credit limit',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/admin/businesses/{business}/apply-interest",
     *     summary="Apply interest charges to business (optional)",
     *     tags={"Admin"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Interest applied")
     * )
     */
    public function applyInterest(Request $request, Business $business)
    {
        $request->validate([
            'interest_amount' => 'required|numeric|min:1|max:999999',
            'reason' => 'required|string|max:500',
        ]);

        if ($business->getOutstandingDebt() <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Business has no outstanding debt to apply interest to'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $interestAmount = $request->interest_amount;
            $reason = $request->reason;

            // Apply interest (increases debt, reduces spending power)
            $business->applyInterest($interestAmount, $reason);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Interest applied successfully',
                'data' => [
                    'business' => $business->fresh(),
                    'interest_applied' => $interestAmount,
                    'reason' => $reason,
                    'updated_balances' => [
                        'outstanding_debt' => $business->fresh()->credit_balance,
                        'available_spending_power' => $business->fresh()->available_balance,
                        'credit_utilization' => $business->fresh()->getCreditUtilization(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to apply interest',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/admin/businesses/{business}/treasury/update",
     *     summary="Update platform treasury for business (admin funds management)",
     *     tags={"Admin"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Treasury updated")
     * )
     */
    public function updateTreasury(Request $request, Business $business)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1|max:999999999',
            'operation' => 'required|in:add,subtract',
            'description' => 'required|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $amount = $request->amount;
            $operation = $request->operation;
            $description = $request->description;
            $admin = Auth::user();

            // Update treasury (platform funds)
            $business->updateTreasury($amount, $operation, $description, $admin->id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Treasury updated successfully',
                'data' => [
                    'business' => $business->fresh(),
                    'treasury_operation' => [
                        'amount' => $amount,
                        'operation' => $operation,
                        'description' => $description,
                    ],
                    'new_treasury_balance' => $business->fresh()->treasury_collateral_balance,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update treasury',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/businesses",
     *     summary="Get all businesses with their credit status",
     *     tags={"Admin"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Businesses retrieved")
     * )
     */
    public function getBusinesses(Request $request)
    {
        $query = Business::with(['riskTier', 'createdBy']);

        // Filter by status if provided
        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        // Filter by high utilization
        if ($request->filled('high_utilization')) {
            $query->whereRaw('(credit_balance / current_balance) > 0.8');
        }

        $businesses = $query->orderBy('created_at', 'desc')->paginate(20);

        // Add calculated metrics to each business
        $businesses->getCollection()->transform(function ($business) {
            $business->credit_metrics = [
                'total_assigned_credit' => $business->getTotalAssignedCredit(),
                'available_spending_power' => $business->getAvailableSpendingPower(),
                'outstanding_debt' => $business->getOutstandingDebt(),
                'credit_utilization' => $business->getCreditUtilization(),
                'spending_power_utilization' => $business->getSpendingPowerUtilization(),
                'payment_score' => $business->getPaymentScore(),
            ];
            return $business;
        });

        return response()->json([
            'success' => true,
            'message' => 'Businesses retrieved successfully',
            'data' => $businesses
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/admin/businesses/{business}",
     *     summary="Get detailed business information",
     *     tags={"Admin"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Business details retrieved")
     * )
     */
    public function getBusinessDetails(Business $business)
    {
        $business->load(['riskTier', 'createdBy', 'purchaseOrders.vendor', 'payments.purchaseOrder']);

        $recentTransactions = $business->balanceTransactions()
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Business details retrieved successfully',
            'data' => [
                'business' => $business,
                'credit_summary' => [
                    'total_assigned_credit' => $business->getTotalAssignedCredit(),
                    'available_spending_power' => $business->getAvailableSpendingPower(),
                    'outstanding_debt' => $business->getOutstandingDebt(),
                    'credit_utilization' => $business->getCreditUtilization(),
                    'spending_power_utilization' => $business->getSpendingPowerUtilization(),
                ],
                'performance_metrics' => [
                    'payment_score' => $business->getPaymentScore(),
                    'average_payment_time' => $business->getAveragePaymentTime(),
                    'total_purchase_orders' => $business->purchaseOrders->count(),
                    'total_payments' => $business->payments->count(),
                ],
                'recent_transactions' => $recentTransactions,
                'validation_errors' => $business->validateBalances(),
            ]
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/admin/bulk-interest-application",
     *     summary="Apply interest to multiple businesses with outstanding debt",
     *     tags={"Admin"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Bulk interest application completed")
     * )
     */
    public function bulkApplyInterest(Request $request)
    {
        $request->validate([
            'apply_to' => 'required|in:all,high_utilization,overdue',
            'interest_rate_override' => 'nullable|numeric|min:0|max:100',
            'reason' => 'required|string|max:500',
        ]);

        $query = Business::where('credit_balance', '>', 0)->where('is_active', true);

        // Apply filters based on selection
        switch ($request->apply_to) {
            case 'high_utilization':
                $query->whereRaw('(credit_balance / current_balance) > 0.8');
                break;
            case 'overdue':
                $query->whereHas('purchaseOrders', function($q) {
                    $q->overdue();
                });
                break;
            // 'all' doesn't need additional filtering
        }

        $businesses = $query->get();
        $totalInterestApplied = 0;
        $businessesProcessed = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($businesses as $business) {
                try {
                    // Calculate interest amount
                    $interestRate = $request->interest_rate_override ?? $business->getEffectiveInterestRate();

                    if ($interestRate > 0) {
                        $dailyRate = $interestRate / 365 / 100;
                        $interestAmount = round($business->getOutstandingDebt() * $dailyRate, 2);

                        if ($interestAmount > 0) {
                            $business->applyInterest($interestAmount, $request->reason);
                            $totalInterestApplied += $interestAmount;
                            $businessesProcessed++;
                        }
                    }

                } catch (\Exception $e) {
                    $errors[] = [
                        'business_id' => $business->id,
                        'business_name' => $business->name,
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bulk interest application completed',
                'data' => [
                    'businesses_processed' => $businessesProcessed,
                    'total_interest_applied' => $totalInterestApplied,
                    'average_interest_per_business' => $businessesProcessed > 0 ? $totalInterestApplied / $businessesProcessed : 0,
                    'errors' => $errors,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Bulk interest application failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/admin/platform-summary",
     *     summary="Get platform financial summary",
     *     tags={"Admin"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Platform summary retrieved")
     * )
     */
    public function getPlatformSummary()
    {
        $businesses = Business::all();
        $purchaseOrders = PurchaseOrder::all();
        $payments = Payment::where('status', 'confirmed')->get();

        $summary = [
            'financial_overview' => [
                'total_assigned_credit' => $businesses->sum('current_balance'),
                'total_outstanding_debt' => $businesses->sum('credit_balance'),
                'total_available_spending_power' => $businesses->sum('available_balance'),
                'platform_treasury' => $businesses->sum('treasury_collateral_balance'),
                'total_platform_exposure' => $businesses->sum('credit_balance'),
            ],
            'utilization_metrics' => [
                'average_credit_utilization' => $businesses->avg(function($b) { return $b->getCreditUtilization(); }),
                'high_utilization_businesses' => $businesses->filter(function($b) { return $b->getCreditUtilization() > 80; })->count(),
                'businesses_with_debt' => $businesses->where('credit_balance', '>', 0)->count(),
                'debt_to_credit_ratio' => $businesses->sum('current_balance') > 0 ?
                    ($businesses->sum('credit_balance') / $businesses->sum('current_balance')) * 100 : 0,
            ],
            'payment_performance' => [
                'total_payments_received' => $payments->count(),
                'total_payment_value' => $payments->sum('amount'),
                'average_payment_amount' => $payments->avg('amount'),
                'pending_payments' => Payment::where('status', 'pending')->count(),
                'pending_payment_value' => Payment::where('status', 'pending')->sum('amount'),
            ],
            'risk_indicators' => [
                'overdue_purchase_orders' => PurchaseOrder::overdue()->count(),
                'overdue_value' => PurchaseOrder::overdue()->sum('outstanding_amount'),
                'businesses_over_limit' => $businesses->filter(function($b) {
                    return $b->credit_balance > $b->current_balance;
                })->count(),
                'average_payment_score' => $businesses->avg(function($b) { return $b->getPaymentScore(); }),
            ]
        ];

        return response()->json([
            'success' => true,
            'message' => 'Platform summary retrieved successfully',
            'data' => $summary
        ]);
    }
}

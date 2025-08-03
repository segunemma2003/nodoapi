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
use App\Mail\PurchaseOrderApproved;
use App\Mail\PurchaseOrderRejected;
use App\Mail\VendorApproved;
use App\Mail\VendorRejected;
use App\Models\BalanceTransaction;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * @OA\Tag(
 *     name="Admin",
 *     description="Admin operations - Credit & Payment Management"
 * )
 */
class AdminController extends Controller
{

     protected $paystackService;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystackService = $paystackService;
    }



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
            'initial_credit' => 'required|numeric|min:1000|',
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
                $emailStatus = 'sent';
    $emailMessage = 'Credentials email sent successfully';

    Log::info('Business credentials email sent successfully', [
        'business_id' => $business->id,
        'business_email' => $business->email,
        'created_by' => Auth::id(),
    ]);
            } catch (\Exception $e) {
               $emailStatus = 'failed';
    $emailMessage = 'Failed to send credentials email: ' . $e->getMessage();

    Log::error('Failed to send credentials email', [
        'business_id' => $business->id,
        'business_email' => $business->email,
        'error' => $e->getMessage(),
        'created_by' => Auth::id(),
    ]);
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
            'new_credit_amount' => 'required|numeric|min:0',
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
            'interest_amount' => 'required',
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
            'amount' => 'required|numeric',
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

    /**
 * @OA\Get(
 *     path="/api/admin/purchase-orders",
 *     summary="Get all purchase orders across platform",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Purchase orders retrieved")
 * )
 */
public function getPurchaseOrders(Request $request)
{
    $query = PurchaseOrder::with(['business', 'vendor', 'approvedBy', 'payments']);

    // Filters
    if ($request->filled('status')) {
        $query->where('status', $request->status);
    }
    if ($request->filled('payment_status')) {
        $query->where('payment_status', $request->payment_status);
    }
    if ($request->filled('business_id')) {
        $query->where('business_id', $request->business_id);
    }
    if ($request->filled('overdue_only')) {
        $query->overdue();
    }
    if ($request->filled('high_value')) {
        $query->where('net_amount', '>=', $request->high_value);
    }

    $purchaseOrders = $query->orderBy('created_at', 'desc')->paginate(20);

    // Add calculated fields
    $purchaseOrders->getCollection()->transform(function ($po) {
        $po->days_since_order = $po->getDaysSinceOrder();
        $po->is_overdue = $po->isOverdue();
        $po->days_overdue = $po->getDaysOverdue();
        $po->payment_progress = $po->getPaymentProgress();
        $po->accrued_interest = $po->calculateAccruedInterest();
        return $po;
    });

    return response()->json([
        'success' => true,
        'message' => 'Purchase orders retrieved successfully',
        'data' => $purchaseOrders,
        'summary' => [
            'total_pos' => PurchaseOrder::count(),
            'pending_approval' => PurchaseOrder::where('status', 'pending')->count(),
            'overdue_count' => PurchaseOrder::overdue()->count(),
            'total_value' => PurchaseOrder::sum('net_amount'),
            'outstanding_value' => PurchaseOrder::sum('outstanding_amount'),
        ]
    ]);
}

/**
 * @OA\Get(
 *     path="/api/admin/purchase-orders/{po}",
 *     summary="Get specific purchase order details",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Purchase order details retrieved")
 * )
 */
public function getPurchaseOrderDetails(PurchaseOrder $po)
{
    $po->load(['business', 'vendor', 'approvedBy', 'payments.confirmedBy', 'payments.rejectedBy']);

    return response()->json([
        'success' => true,
        'message' => 'Purchase order details retrieved successfully',
        'data' => [
            'purchase_order' => $po,
            'calculated_metrics' => [
                'days_since_order' => $po->getDaysSinceOrder(),
                'is_overdue' => $po->isOverdue(),
                'days_overdue' => $po->getDaysOverdue(),
                'payment_progress' => $po->getPaymentProgress(),
                'accrued_interest' => $po->calculateAccruedInterest(),
                'late_fees' => $po->calculateLateFees(),
                'total_amount_owed' => $po->getTotalAmountOwed(),
            ],
            'business_context' => [
                'current_utilization' => $po->business->getCreditUtilization(),
                'payment_score' => $po->business->getPaymentScore(),
                'total_debt' => $po->business->getOutstandingDebt(),
            ]
        ]
    ]);
}

/**
 * @OA\Post(
 *     path="/api/admin/purchase-orders/{po}/approve",
 *     summary="Approve purchase order",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Purchase order approved")
 * )
 */
public function approvePurchaseOrder(Request $request, PurchaseOrder $po)
{
    // Debug logging
    Log::info('Approving Purchase Order', [
        'po_id' => $po->id,
        'po_number' => $po->po_number,
        'status' => $po->status,
        'admin_id' => Auth::id()
    ]);

    if ($po->status !== 'pending') {
        return response()->json([
            'success' => false,
            'message' => 'Purchase order is not pending approval'
        ], 400);
    }

    $request->validate([
        'notes' => 'nullable|string|max:500'
    ]);

    DB::beginTransaction();
    try {
        $admin = Auth::user();
        $business = $po->business;
        $vendor = $po->vendor;

        // Enhanced validation: Check if vendor has complete bank account details
        if (!$vendor->hasCompletePaymentDetails()) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Vendor bank account details incomplete. Required: account_number, bank_code, bank_name.',
                'vendor_details' => [
                    'name' => $vendor->name,
                    'email' => $vendor->email,
                    'has_account_number' => !empty($vendor->account_number),
                    'has_bank_code' => !empty($vendor->bank_code),
                    'has_bank_name' => !empty($vendor->bank_name),
                ]
            ], 400);
        }

        // Update PO status to approved
        $po->update([
            'status' => 'approved',
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'notes' => ($po->notes ?? '') . "\nAdmin notes: " . ($request->notes ?? 'Approved'),
        ]);

        // Make automatic payment to VENDOR
        $paymentResult = $this->makePaymentToVendor($po, $vendor);

        if (!$paymentResult['success']) {
            // If payment fails, revert the approval
            $po->update([
                'status' => 'pending',
                'approved_by' => null,
                'approved_at' => null,
            ]);

            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Purchase order approval failed due to payment error',
                'error' => $paymentResult['error'],
                'vendor_bank_details' => $vendor->getBankDetailsFormatted()
            ], 500);
        }

        // Generate PDFs
        $pdfResults = $this->generatePurchaseOrderPDFs($po, $paymentResult);

        DB::commit();

        // Send notifications after successful transaction
        $this->sendApprovalNotifications($po, $business, $vendor, $paymentResult, $pdfResults);

        return response()->json([
            'success' => true,
            'message' => 'Purchase order approved successfully and payment sent to vendor',
            'data' => [
                'purchase_order' => $po->fresh(['business', 'vendor', 'approvedBy']),
                'approval_details' => [
                    'approved_by' => $admin->name,
                    'approved_at' => now(),
                    'notes' => $request->notes,
                ],
                'payment_details' => [
                    'amount_paid' => $po->net_amount,
                    'payment_reference' => $paymentResult['reference'],
                    'payment_status' => 'completed',
                    'recipient' => $vendor->name,
                    'recipient_account' => $vendor->account_number,
                    'recipient_bank' => $vendor->bank_name,
                ],
                'documents' => [
                    'purchase_order_pdf' => $pdfResults['po_pdf_path'] ?? null,
                    'payment_receipt_pdf' => $pdfResults['receipt_pdf_path'] ?? null,
                ]
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollback();
        Log::error('Purchase order approval failed', [
            'po_id' => $po->id,
            'vendor_bank_details' => $vendor->getBankDetailsFormatted(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to approve purchase order',
            'error' => $e->getMessage()
        ], 500);
    }
}

private function makePaymentToVendor(PurchaseOrder $po, $vendor)
{
    try {
        // Create transfer recipient for vendor if not exists
        $recipientData = $this->paystackService->createTransferRecipient([
            'type' => 'nuban',
            'name' => $vendor->name,
            'account_number' => $vendor->account_number,
            'bank_code' => $vendor->bank_code,
            'currency' => 'NGN'
        ]);

        if (!$recipientData['success']) {
            return [
                'success' => false,
                'error' => 'Failed to create transfer recipient: ' . $recipientData['message']
            ];
        }

        // Store recipient code for future use
        if (!$vendor->recipient_code) {
            $vendor->update(['recipient_code' => $recipientData['data']['recipient_code']]);
        }

        // Initiate transfer to vendor
        $transferAmount = $po->net_amount * 100; // Convert to kobo
        $transferData = $this->paystackService->initiateTransfer([
            'source' => 'balance',
            'amount' => $transferAmount,
            'recipient' => $recipientData['data']['recipient_code'],
            'reason' => "Payment for PO #{$po->po_number} - {$po->description}",
            'reference' => 'PO_VENDOR_' . $po->po_number . '_' . time()
        ]);

        if (!$transferData['success']) {
            return [
                'success' => false,
                'error' => 'Failed to initiate transfer: ' . $transferData['message']
            ];
        }

        // Log the payment
        Log::info('Payment sent to vendor', [
            'po_id' => $po->id,
            'vendor_id' => $vendor->id,
            'vendor_name' => $vendor->name,
            'amount' => $po->net_amount,
            'transfer_reference' => $transferData['data']['reference'],
            'transfer_code' => $transferData['data']['transfer_code']
        ]);

        return [
            'success' => true,
            'reference' => $transferData['data']['reference'],
            'transfer_code' => $transferData['data']['transfer_code'],
            'amount' => $po->net_amount,
            'recipient_code' => $recipientData['data']['recipient_code']
        ];

    } catch (\Exception $e) {
        Log::error('Paystack vendor payment failed', [
            'po_id' => $po->id,
            'vendor_id' => $vendor->id,
            'error' => $e->getMessage()
        ]);

        return [
            'success' => false,
            'error' => 'Payment service error: ' . $e->getMessage()
        ];
    }
}


private function generatePurchaseOrderPDFs(PurchaseOrder $po, $paymentResult)
{
    try {
        $results = [];

        // Create directories if they don't exist
        $poDir = storage_path('app/private/purchase_orders');
        $receiptDir = storage_path('app/private/payment_receipts');

        if (!file_exists($poDir)) {
            mkdir($poDir, 0755, true);
        }
        if (!file_exists($receiptDir)) {
            mkdir($receiptDir, 0755, true);
        }

        // Generate Purchase Order PDF
        $poPdfPath = $poDir . '/po_' . $po->id . '.pdf';
        $poHtml = $this->generatePurchaseOrderHTML($po);
        $this->generatePDFFromHTML($poHtml, $poPdfPath);
        $results['po_pdf_path'] = $poPdfPath;

        // Generate Payment Receipt PDF
        $receiptPdfPath = $receiptDir . '/payment_' . $paymentResult['transfer_code'] . '.pdf';
        $receiptHtml = $this->generatePaymentReceiptHTML($po, $paymentResult);
        $this->generatePDFFromHTML($receiptHtml, $receiptPdfPath);
        $results['receipt_pdf_path'] = $receiptPdfPath;

        Log::info('PDFs generated successfully', [
            'po_id' => $po->id,
            'po_pdf' => $poPdfPath,
            'receipt_pdf' => $receiptPdfPath
        ]);

        return $results;

    } catch (\Exception $e) {
        Log::error('PDF generation failed', [
            'po_id' => $po->id,
            'error' => $e->getMessage()
        ]);

        return [
            'po_pdf_path' => null,
            'receipt_pdf_path' => null,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Generate Purchase Order HTML for PDF
 */
private function generatePurchaseOrderHTML(PurchaseOrder $po)
{
    $business = $po->business;
    $vendor = $po->vendor;
    $items = $po->items ?? [];

    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <title>Purchase Order #{$po->po_number}</title>
        <style>
            body {
                font-family: 'DejaVu Sans', Arial, sans-serif;
                margin: 0;
                padding: 20px;
                color: #333;
                line-height: 1.6;
            }
            .header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 3px solid #4CAF50;
                padding-bottom: 20px;
            }
            .header h1 {
                color: #4CAF50;
                margin: 0;
                font-size: 28px;
            }
            .header h2 {
                color: #666;
                margin: 10px 0 0 0;
                font-size: 20px;
            }
            .company-info {
                margin-bottom: 20px;
                padding: 15px;
                background-color: #f9f9f9;
                border-radius: 5px;
            }
            .po-details {
                display: table;
                width: 100%;
                margin-bottom: 30px;
            }
            .po-left, .po-right {
                display: table-cell;
                width: 50%;
                vertical-align: top;
                padding: 0 10px;
            }
            .po-right {
                text-align: right;
            }
            .items-table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .items-table th, .items-table td {
                border: 1px solid #ddd;
                padding: 12px 8px;
                text-align: left;
            }
            .items-table th {
                background-color: #4CAF50;
                color: white;
                font-weight: bold;
            }
            .items-table tr:nth-child(even) {
                background-color: #f2f2f2;
            }
            .totals {
                text-align: right;
                margin-top: 30px;
                padding: 20px;
                background-color: #f9f9f9;
                border-radius: 5px;
            }
            .total-row {
                margin: 8px 0;
                font-size: 16px;
            }
            .final-total {
                font-weight: bold;
                font-size: 20px;
                color: #4CAF50;
                border-top: 2px solid #4CAF50;
                padding-top: 10px;
            }
            .status-badge {
                display: inline-block;
                padding: 5px 15px;
                border-radius: 20px;
                color: white;
                font-weight: bold;
                text-transform: uppercase;
                background-color: #4CAF50;
            }
            .footer {
                margin-top: 40px;
                text-align: center;
                color: #666;
                font-size: 14px;
                border-top: 1px solid #ddd;
                padding-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>PURCHASE ORDER</h1>
            <h2>#{$po->po_number}</h2>
            <span class='status-badge'>APPROVED</span>
        </div>

        <div class='po-details'>
            <div class='po-left'>
                <h3 style='color: #4CAF50; margin-bottom: 15px;'>From:</h3>
                <div class='company-info'>
                    <strong style='font-size: 18px;'>{$business->name}</strong><br>
                    <strong>Email:</strong> {$business->email}<br>
                    " . ($business->phone ? "<strong>Phone:</strong> {$business->phone}<br>" : '') . "
                    " . ($business->address ? "<strong>Address:</strong><br>" . nl2br($business->address) : '') . "
                </div>
            </div>
            <div class='po-right'>
                <h3 style='color: #4CAF50; margin-bottom: 15px;'>To:</h3>
                <div class='company-info'>
                    <strong style='font-size: 18px;'>{$vendor->name}</strong><br>
                    <strong>Email:</strong> {$vendor->email}<br>
                    " . ($vendor->phone ? "<strong>Phone:</strong> {$vendor->phone}<br>" : '') . "
                    " . ($vendor->address ? "<strong>Address:</strong><br>" . nl2br($vendor->address) : '') . "
                </div>
            </div>
        </div>

        <div class='po-details'>
            <div class='po-left'>
                <strong>Order Date:</strong> {$po->order_date->format('F j, Y')}<br>
                <strong>Due Date:</strong> {$po->due_date->format('F j, Y')}<br>
                " . ($po->expected_delivery_date ? "<strong>Expected Delivery:</strong> {$po->expected_delivery_date->format('F j, Y')}<br>" : '') . "
            </div>
            <div class='po-right'>
                <strong>PO Number:</strong> {$po->po_number}<br>
                <strong>Status:</strong> " . ucfirst($po->status) . "<br>
                <strong>Approved Date:</strong> {$po->approved_at->format('F j, Y g:i A')}
            </div>
        </div>

        " . ($po->description ? "<div style='margin: 20px 0; padding: 15px; background-color: #f0f8ff; border-left: 4px solid #4CAF50;'><strong>Description:</strong> {$po->description}</div>" : '') . "

        <table class='items-table'>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Description</th>
                    <th style='text-align: center;'>Quantity</th>
                    <th style='text-align: right;'>Unit Price</th>
                    <th style='text-align: right;'>Total</th>
                </tr>
            </thead>
            <tbody>";

    foreach ($items as $item) {
        $itemTotal = $item['quantity'] * $item['unit_price'];
        $html .= "
                <tr>
                    <td><strong>{$item['name']}</strong></td>
                    <td>" . ($item['description'] ?? 'N/A') . "</td>
                    <td style='text-align: center;'>" . number_format($item['quantity'], 2) . "</td>
                    <td style='text-align: right;'>₦" . number_format($item['unit_price'], 2) . "</td>
                    <td style='text-align: right;'><strong>₦" . number_format($itemTotal, 2) . "</strong></td>
                </tr>";
    }

    $html .= "
            </tbody>
        </table>

        <div class='totals'>
            <div class='total-row'>Subtotal: ₦" . number_format($po->total_amount, 2) . "</div>";

    if ($po->tax_amount > 0) {
        $html .= "<div class='total-row'>Tax: ₦" . number_format($po->tax_amount, 2) . "</div>";
    }
    if ($po->discount_amount > 0) {
        $html .= "<div class='total-row'>Discount: -₦" . number_format($po->discount_amount, 2) . "</div>";
    }

    $html .= "
            <div class='total-row final-total'>Total Amount: ₦" . number_format($po->net_amount, 2) . "</div>
        </div>

        " . ($po->notes ? "<div style='margin-top: 30px; padding: 15px; background-color: #fff9c4; border-radius: 5px;'><strong>Notes:</strong><br>" . nl2br($po->notes) . "</div>" : '') . "

        <div class='footer'>
            <p><strong>This purchase order was approved on {$po->approved_at->format('F j, Y')} and payment has been processed.</strong></p>
            <p>For any inquiries, please contact us at " . config('mail.from.address') . "</p>
            <p>Generated on " . now()->format('F j, Y g:i A') . "</p>
        </div>
    </body>
    </html>";

    return $html;
}
/**
 * Generate Payment Receipt HTML for PDF
 */
private function generatePaymentReceiptHTML(PurchaseOrder $po, $paymentResult)
{
    $vendor = $po->vendor;
    $business = $po->business;

    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <title>Payment Receipt - {$paymentResult['reference']}</title>
        <style>
            body {
                font-family: 'DejaVu Sans', Arial, sans-serif;
                margin: 0;
                padding: 20px;
                color: #333;
                line-height: 1.6;
            }
            .header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 3px solid #28a745;
                padding-bottom: 20px;
            }
            .header h1 {
                color: #28a745;
                margin: 0;
                font-size: 28px;
            }
            .header h2 {
                color: #666;
                margin: 10px 0 0 0;
                font-size: 18px;
            }
            .receipt-info {
                margin: 20px 0;
            }
            .info-table {
                width: 100%;
                border-collapse: collapse;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .info-table td {
                padding: 15px;
                border-bottom: 1px solid #eee;
                vertical-align: top;
            }
            .info-table tr:nth-child(even) {
                background-color: #f9f9f9;
            }
            .label {
                font-weight: bold;
                width: 200px;
                color: #555;
            }
            .amount {
                font-size: 28px;
                font-weight: bold;
                color: #28a745;
                text-align: center;
                padding: 20px;
                background-color: #f0f8f0;
                border-radius: 10px;
                margin: 20px 0;
            }
            .status-success {
                color: #28a745;
                font-weight: bold;
                font-size: 18px;
            }
            .section-title {
                color: #28a745;
                font-size: 18px;
                font-weight: bold;
                margin: 30px 0 15px 0;
                border-bottom: 2px solid #28a745;
                padding-bottom: 5px;
            }
            .footer {
                margin-top: 40px;
                text-align: center;
                color: #666;
                font-size: 14px;
                border-top: 1px solid #ddd;
                padding-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>PAYMENT RECEIPT</h1>
            <h2>#{$paymentResult['reference']}</h2>
        </div>

        <div class='amount'>₦" . number_format($paymentResult['amount'], 2) . "</div>

        <h3 class='section-title'>Payment Information</h3>
        <div class='receipt-info'>
            <table class='info-table'>
                <tr>
                    <td class='label'>Payment Date:</td>
                    <td>" . now()->format('F j, Y g:i A') . "</td>
                </tr>
                <tr>
                    <td class='label'>Payment Reference:</td>
                    <td><strong>{$paymentResult['reference']}</strong></td>
                </tr>
                <tr>
                    <td class='label'>Transfer Code:</td>
                    <td>{$paymentResult['transfer_code']}</td>
                </tr>
                <tr>
                    <td class='label'>Purchase Order:</td>
                    <td><strong>#{$po->po_number}</strong></td>
                </tr>
                <tr>
                    <td class='label'>Payment Method:</td>
                    <td>Bank Transfer (Paystack)</td>
                </tr>
                <tr>
                    <td class='label'>Status:</td>
                    <td><span class='status-success'>COMPLETED</span></td>
                </tr>
            </table>
        </div>

        <h3 class='section-title'>Recipient Details</h3>
        <div class='receipt-info'>
            <table class='info-table'>
                <tr>
                    <td class='label'>Vendor Name:</td>
                    <td><strong>{$vendor->name}</strong></td>
                </tr>
                <tr>
                    <td class='label'>Account Number:</td>
                    <td>{$vendor->account_number}</td>
                </tr>
                <tr>
                    <td class='label'>Bank:</td>
                    <td>{$vendor->bank_name}</td>
                </tr>
                <tr>
                    <td class='label'>Account Holder:</td>
                    <td>" . ($vendor->account_holder_name ?? $vendor->name) . "</td>
                </tr>
            </table>
        </div>

        <h3 class='section-title'>Transaction Details</h3>
        <div class='receipt-info'>
            <table class='info-table'>
                <tr>
                    <td class='label'>Paying Business:</td>
                    <td><strong>{$business->name}</strong></td>
                </tr>
                <tr>
                    <td class='label'>Business Email:</td>
                    <td>{$business->email}</td>
                </tr>
                <tr>
                    <td class='label'>Description:</td>
                    <td>Payment for Purchase Order #{$po->po_number}" . ($po->description ? " - {$po->description}" : "") . "</td>
                </tr>
                <tr>
                    <td class='label'>Processing Fee:</td>
                    <td>Covered by FoodStuff Platform</td>
                </tr>
            </table>
        </div>

        <div class='footer'>
            <p><strong>This is an automated receipt generated upon successful payment processing.</strong></p>
            <p>For any inquiries, please contact support at " . config('mail.from.address') . "</p>
            <p>Generated on " . now()->format('F j, Y g:i A') . "</p>
        </div>
    </body>
    </html>";
}
/**
 * Generate PDF from HTML using DomPDF (you'll need to install this)
 * Run: composer require dompdf/dompdf
 */
private function generatePDFFromHTML($html, $filePath)
{
    try {
        // Create DomPDF instance with options
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);

        // Load HTML content
        $dompdf->loadHtml($html);

        // Set paper size and orientation
        $dompdf->setPaper('A4', 'portrait');

        // Render PDF
        $dompdf->render();

        // Save PDF to file
        file_put_contents($filePath, $dompdf->output());

        return true;

    } catch (\Exception $e) {
        Log::error('PDF generation failed', [
            'file_path' => $filePath,
            'error' => $e->getMessage()
        ]);

        return false;
    }
}

/**
 * @OA\Post(
 *     path="/api/admin/purchase-orders/{po}/reject",
 *     summary="Reject purchase order",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Purchase order rejected")
 * )
 */
public function rejectPurchaseOrder(Request $request, PurchaseOrder $po)
    {
        // Debug logging
        Log::info('Rejecting Purchase Order', [
            'po_id' => $po->id,
            'po_number' => $po->po_number,
            'status' => $po->status,
            'admin_id' => Auth::id()
        ]);

        if ($po->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order is not pending approval'
            ], 400);
        }

        $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        DB::beginTransaction();
        try {
            $admin = Auth::user();
            $business = $po->business;
            $vendor = $po->vendor;

            // If PO was already reducing spending power, restore it
            if ($po->status === 'pending') {
                $business->available_balance += $po->net_amount;
                $business->credit_balance -= $po->net_amount;
                $business->save();

                // Log the restoration
                try {
                    $business->logBalanceTransaction(
                        'available',
                        $po->net_amount,
                        'credit',
                        'Spending power restored due to PO rejection',
                        'po_rejection',
                        $po->id
                    );
                } catch (\Exception $e) {
                    Log::error('Failed to log balance transaction for PO rejection', [
                        'po_id' => $po->id,
                        'business_id' => $business->id,
                        'error' => $e->getMessage()
                    ]);
                    // Continue with the rejection even if logging fails
                }
            }

            $po->update([
                'status' => 'rejected',
                'approved_by' => $admin->id,
                'approved_at' => now(),
                'notes' => ($po->notes ?? '') . "\nRejection reason: " . $request->reason,
            ]);

            DB::commit();

            // Send rejection notification only to vendor
            if ($vendor && $vendor->email) {
                $this->sendRejectionNotifications($po, $vendor, $request->reason);
            } else {
                Log::warning('Cannot send rejection notification - vendor email missing', [
                    'po_id' => $po->id,
                    'vendor_id' => $vendor->id ?? 'No vendor',
                    'vendor_email' => $vendor->email ?? 'No email'
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Purchase order rejected successfully',
                'data' => [
                    'purchase_order' => $po->fresh(['business', 'vendor']),
                    'rejection_details' => [
                        'rejected_by' => $admin->name,
                        'rejected_at' => now(),
                        'reason' => $request->reason,
                    ],
                    'business_balances_restored' => [
                        'available_spending_power' => $business->fresh()->available_balance,
                        'outstanding_debt' => $business->fresh()->credit_balance,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject purchase order',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Make payment to business via Paystack
     */
    private function makePaymentToBusiness(PurchaseOrder $po, Business $business)
    {
        try {
            // Check if business has bank account details
            if (!$business->bank_account_number || !$business->bank_code) {
                return [
                    'success' => false,
                    'error' => 'Business bank account details not configured'
                ];
            }

            // Create transfer recipient if not exists
            $recipientData = $this->paystackService->createTransferRecipient([
                'type' => 'nuban',
                'name' => $business->name,
                'account_number' => $business->bank_account_number,
                'bank_code' => $business->bank_code,
                'currency' => 'NGN'
            ]);

            if (!$recipientData['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to create transfer recipient: ' . $recipientData['message']
                ];
            }

            // Initiate transfer
            $transferAmount = $po->net_amount * 100; // Convert to kobo
            $transferData = $this->paystackService->initiateTransfer([
                'source' => 'balance',
                'amount' => $transferAmount,
                'recipient' => $recipientData['data']['recipient_code'],
                'reason' => "Payment for PO #{$po->po_number} - {$po->description}",
                'reference' => 'PO_' . $po->po_number . '_' . time()
            ]);

            if (!$transferData['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to initiate transfer: ' . $transferData['message']
                ];
            }

            // Log the payment
            Log::info('Automatic payment sent to business', [
                'po_id' => $po->id,
                'business_id' => $business->id,
                'amount' => $po->net_amount,
                'transfer_reference' => $transferData['data']['reference'],
                'transfer_code' => $transferData['data']['transfer_code']
            ]);

            return [
                'success' => true,
                'reference' => $transferData['data']['reference'],
                'transfer_code' => $transferData['data']['transfer_code'],
                'amount' => $po->net_amount
            ];

        } catch (\Exception $e) {
            Log::error('Paystack payment failed', [
                'po_id' => $po->id,
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Payment service error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send approval notifications to business and vendor
     */
   private function sendApprovalNotifications(PurchaseOrder $po, Business $business, $vendor, $paymentResult, $pdfResults = [])
{
    try {
        // Notify business about approval (but vendor got paid, not business)
        Mail::to($business->email)->send(new PurchaseOrderApproved($po, $paymentResult, 'business'));

        // Notify vendor about approval and payment received
        Mail::to($vendor->email)->send(new PurchaseOrderApproved($po, $paymentResult, 'vendor'));

        Log::info('Approval notifications sent', [
            'po_id' => $po->id,
            'business_email' => $business->email,
            'vendor_email' => $vendor->email,
            'payment_amount' => $paymentResult['amount'],
        ]);

    } catch (\Exception $e) {
        Log::error('Failed to send approval notifications', [
            'po_id' => $po->id,
            'error' => $e->getMessage(),
        ]);
    }
}
    /**
     * Send rejection notification to vendor only
     */
    private function sendRejectionNotifications(PurchaseOrder $po, $vendor, $reason)
    {
        try {
            // Only notify vendor about rejection
            Mail::to($vendor->email)->send(new PurchaseOrderRejected($po, $reason));

            Log::info('Rejection notification sent to vendor', [
                'po_id' => $po->id,
                'vendor_email' => $vendor->email,
                'reason' => $reason,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send rejection notification', [
                'po_id' => $po->id,
                'vendor_email' => $vendor->email ?? 'No email',
                'error' => $e->getMessage(),
            ]);
            // Don't throw the exception - just log it
        }
    }

/**
 * @OA\Put(
 *     path="/api/admin/purchase-orders/{po}/update-status",
 *     summary="Update purchase order status",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Purchase order status updated")
 * )
 */
public function updatePurchaseOrderStatus(Request $request, PurchaseOrder $po)
{
    $request->validate([
        'status' => 'required|in:draft,pending,approved,rejected,completed,cancelled',
        'notes' => 'nullable|string|max:500'
    ]);

    $oldStatus = $po->status;
    $newStatus = $request->status;

    if ($oldStatus === $newStatus) {
        return response()->json([
            'success' => false,
            'message' => 'Purchase order is already in the specified status'
        ], 400);
    }

    DB::beginTransaction();
    try {
        $admin = Auth::user();

        $po->update([
            'status' => $newStatus,
            'notes' => ($po->notes ?? '') . "\nStatus changed from {$oldStatus} to {$newStatus}: " . ($request->notes ?? ''),
        ]);

        // Handle status-specific logic
        if ($newStatus === 'approved' && $oldStatus === 'pending') {
            $po->update([
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => "Purchase order status updated from {$oldStatus} to {$newStatus}",
            'data' => [
                'purchase_order' => $po->fresh(['business', 'vendor', 'approvedBy']),
                'status_change' => [
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'updated_by' => $admin->name,
                    'updated_at' => now(),
                ]
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollback();
        return response()->json([
            'success' => false,
            'message' => 'Failed to update purchase order status',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * USER MANAGEMENT
 * ===============
 */

/**
 * @OA\Get(
 *     path="/api/admin/users",
 *     summary="Get all users in the system",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Users retrieved")
 * )
 */
public function getUsers(Request $request)
{
    $query = User::query();

    // Filters
    if ($request->filled('user_type')) {
        $query->where('user_type', $request->user_type);
    }
    if ($request->filled('is_active')) {
        $query->where('is_active', $request->is_active);
    }
    if ($request->filled('role')) {
        $query->where('role', $request->role);
    }

    $users = $query->withCount('createdBusinesses')
                  ->orderBy('created_at', 'desc')
                  ->paginate(20);

    return response()->json([
        'success' => true,
        'message' => 'Users retrieved successfully',
        'data' => $users,
        'summary' => [
            'total_users' => User::count(),
            'admin_users' => User::where('user_type', 'admin')->count(),
            'active_users' => User::where('is_active', true)->count(),
        ]
    ]);
}

/**
 * @OA\Post(
 *     path="/api/admin/users",
 *     summary="Create new user",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=201, description="User created")
 * )
 */
public function createUser(Request $request)
{
    $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users,email',
        'password' => 'required|string|min:8',
        'user_type' => 'required|in:admin,business',
        'role' => 'required|in:super_admin,admin,business',
        'is_active' => 'boolean',
    ]);

    try {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'user_type' => $request->user_type,
            'role' => $request->role,
            'is_active' => $request->is_active ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to create user',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * @OA\Put(
 *     path="/api/admin/users/{user}",
 *     summary="Update user",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="User updated")
 * )
 */
public function updateUser(Request $request, User $user)
{
    $request->validate([
        'name' => 'string|max:255',
        'email' => 'email|unique:users,email,' . $user->id,
        'password' => 'nullable|string|min:8',
        'user_type' => 'in:admin,business',
        'role' => 'in:super_admin,admin,business',
        'is_active' => 'boolean',
    ]);

    try {
        $updateData = $request->only(['name', 'email', 'user_type', 'role', 'is_active']);

        if ($request->filled('password')) {
            $updateData['password'] = $request->password;
        }

        $user->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user->fresh()
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to update user',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * @OA\Delete(
 *     path="/api/admin/users/{user}",
 *     summary="Delete user",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="User deleted")
 * )
 */
public function deleteUser(User $user)
{
    // Prevent deleting the current admin
    if ($user->id === Auth::id()) {
        return response()->json([
            'success' => false,
            'message' => 'Cannot delete your own account'
        ], 400);
    }

    // Check if user has created businesses
    $businessCount = $user->createdBusinesses()->count();
    if ($businessCount > 0) {
        return response()->json([
            'success' => false,
            'message' => "Cannot delete user who has created {$businessCount} businesses. Deactivate instead."
        ], 400);
    }

    try {
        $userName = $user->name;
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => "User '{$userName}' deleted successfully"
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to delete user',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * PAYMENT HISTORY & TRANSACTIONS
 * ==============================
 */

/**
 * @OA\Get(
 *     path="/api/admin/payments/history",
 *     summary="Get comprehensive payment history",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Payment history retrieved")
 * )
 */
public function getPaymentHistory(Request $request)
{
    $query = Payment::with(['business', 'purchaseOrder.vendor', 'confirmedBy', 'rejectedBy']);

    // Filters
    if ($request->filled('status')) {
        $query->where('status', $request->status);
    }
    if ($request->filled('business_id')) {
        $query->where('business_id', $request->business_id);
    }
    if ($request->filled('payment_type')) {
        $query->where('payment_type', $request->payment_type);
    }
    if ($request->filled('date_from')) {
        $query->whereDate('payment_date', '>=', $request->date_from);
    }
    if ($request->filled('date_to')) {
        $query->whereDate('payment_date', '<=', $request->date_to);
    }
    if ($request->filled('min_amount')) {
        $query->where('amount', '>=', $request->min_amount);
    }

    $payments = $query->orderBy('created_at', 'desc')->paginate(20);

    return response()->json([
        'success' => true,
        'message' => 'Payment history retrieved successfully',
        'data' => $payments,
        'summary' => [
            'total_payments' => Payment::count(),
            'confirmed_payments' => Payment::where('status', 'confirmed')->count(),
            'pending_payments' => Payment::where('status', 'pending')->count(),
            'rejected_payments' => Payment::where('status', 'rejected')->count(),
            'total_confirmed_value' => Payment::where('status', 'confirmed')->sum('amount'),
            'pending_value' => Payment::where('status', 'pending')->sum('amount'),
        ]
    ]);
}

/**
 * @OA\Get(
 *     path="/api/admin/transactions/recent",
 *     summary="Get recent balance transactions across platform",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Recent transactions retrieved")
 * )
 */
public function getRecentTransactions(Request $request)
{
    $limit = $request->input('limit', 50);
    $businessId = $request->input('business_id');

    $query = BalanceTransaction::with('business');

    if ($businessId) {
        $query->where('business_id', $businessId);
    }

    $transactions = $query->orderBy('created_at', 'desc')
                          ->limit($limit)
                          ->get();

    // Group by transaction type for summary
    $summary = [
        'credit_transactions' => $transactions->where('transaction_type', 'credit')->count(),
        'debit_transactions' => $transactions->where('transaction_type', 'debit')->count(),
        'total_credit_amount' => $transactions->where('transaction_type', 'credit')->sum('amount'),
        'total_debit_amount' => $transactions->where('transaction_type', 'debit')->sum('amount'),
        'balance_types' => $transactions->groupBy('balance_type')->map->count(),
    ];

    return response()->json([
        'success' => true,
        'message' => 'Recent transactions retrieved successfully',
        'data' => $transactions,
        'summary' => $summary
    ]);
}

/**
 * BUSINESS REPAYMENTS
 * ===================
 */

/**
 * @OA\Get(
 *     path="/api/admin/businesses/{business}/repayments",
 *     summary="Get repayment history for specific business",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Business repayments retrieved")
 * )
 */
public function getBusinessRepayments(Business $business)
{
    $repayments = $business->payments()
                          ->with(['purchaseOrder.vendor', 'confirmedBy', 'rejectedBy'])
                          ->orderBy('created_at', 'desc')
                          ->paginate(20);

    $summary = [
        'total_repayments' => $business->payments()->count(),
        'confirmed_repayments' => $business->payments()->where('status', 'confirmed')->count(),
        'pending_repayments' => $business->payments()->where('status', 'pending')->count(),
        'rejected_repayments' => $business->payments()->where('status', 'rejected')->count(),
        'total_repaid' => $business->payments()->where('status', 'confirmed')->sum('amount'),
        'pending_repayment_value' => $business->payments()->where('status', 'pending')->sum('amount'),
        'average_repayment_time' => $business->getAveragePaymentTime(),
        'payment_score' => $business->getPaymentScore(),
    ];

    return response()->json([
        'success' => true,
        'message' => 'Business repayments retrieved successfully',
        'data' => [
            'business' => $business,
            'repayments' => $repayments,
            'summary' => $summary,
            'current_balances' => [
                'outstanding_debt' => $business->getOutstandingDebt(),
                'available_spending_power' => $business->getAvailableSpendingPower(),
                'credit_utilization' => $business->getCreditUtilization(),
            ]
        ]
    ]);
}

/**
 * @OA\Post(
 *     path="/api/admin/businesses/{business}/repayments/{payment}/approve",
 *     summary="Approve specific repayment (alias for payment approval)",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Repayment approved")
 * )
 */
public function approveBusinessRepayment(Request $request, Business $business, Payment $payment)
{
    // Verify payment belongs to business
    if ($payment->business_id !== $business->id) {
        return response()->json([
            'success' => false,
            'message' => 'Payment does not belong to specified business'
        ], 400);
    }

    // Use existing payment approval logic
    return $this->approvePayment($request, $payment);
}

/**
 * @OA\Post(
 *     path="/api/admin/businesses/{business}/repayments/{payment}/reject",
 *     summary="Reject specific repayment (alias for payment rejection)",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Repayment rejected")
 * )
 */
public function rejectBusinessRepayment(Request $request, Business $business, Payment $payment)
{
    // Verify payment belongs to business
    if ($payment->business_id !== $business->id) {
        return response()->json([
            'success' => false,
            'message' => 'Payment does not belong to specified business'
        ], 400);
    }

    // Use existing payment rejection logic
    return $this->rejectPayment($request, $payment);
}

/**
 * ENHANCED BUSINESS LIST WITH DETAILED METRICS
 * ============================================
 */

/**
 * Override the existing getBusinesses method with enhanced data
 */

/**
 * Calculate risk level for business
 */
private function calculateRiskLevel(Business $business)
{
    $utilization = $business->getCreditUtilization();
    $paymentScore = $business->getPaymentScore();
    $overdueCount = $business->purchaseOrders()->overdue()->count();

    if ($utilization > 90 || $paymentScore < 60 || $overdueCount > 5) {
        return 'high';
    } elseif ($utilization > 70 || $paymentScore < 80 || $overdueCount > 2) {
        return 'medium';
    } else {
        return 'low';
    }
}

/**
 * @OA\Get(
 *     path="/api/admin/profile",
 *     summary="Get admin profile information",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Admin profile retrieved")
 * )
 */
public function getAdminProfile()
{
    $admin = Auth::user();

    if(!$admin || !($admin instanceof User) || !$admin->isAdmin()) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized - Admin access required'
        ], 403);
    }

    return response()->json([
        'success' => true,
        'message' => 'Admin profile retrieved successfully',
        'data' => [
            'admin_info' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'user_type' => $admin->user_type,
                'is_active' => $admin->is_active,
                'created_at' => $admin->created_at,
            ],
            'work_summary' => [
                'businesses_created' => $admin->createdBusinesses()->count(),
                'purchase_orders_approved' => $admin->approvedPurchaseOrders()->count(),
                'payments_processed' => Payment::where('confirmed_by', $admin->id)->count(),
                'support_performance' => $admin->getSupportPerformanceMetrics(30),
            ],
            'recent_activity' => [
                'last_business_created' => $admin->createdBusinesses()->latest()->first()?->created_at,
                'last_payment_approved' => Payment::where('confirmed_by', $admin->id)->latest('confirmed_at')->first()?->confirmed_at,
                'last_po_approved' => $admin->approvedPurchaseOrders()->latest('approved_at')->first()?->approved_at,
            ]
        ]
    ]);
}

/**
 * @OA\Put(
 *     path="/api/admin/profile",
 *     summary="Update admin profile information",
 *     tags={"Admin"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Admin profile updated")
 * )
 */
public function updateAdminProfile(Request $request)
{
    $admin = Auth::user();
    if(!$admin || !($admin instanceof User) || !$admin->isAdmin()) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized - Admin access required'
        ], 403);
    }

    $request->validate([
        'name' => 'string|max:255',
        'email' => 'email|unique:users,email,' . $admin->id,
    ]);

    // Track changes
    $originalData = $admin->only(['name', 'email']);
    $changedFields = [];

    DB::beginTransaction();
    try {
        $updateData = array_filter($request->only(['name', 'email']),
            function($value) { return !is_null($value); });

        foreach ($updateData as $field => $newValue) {
            if ($admin->$field !== $newValue) {
                $changedFields[$field] = [
                    'old' => $admin->$field,
                    'new' => $newValue
                ];
            }
        }

        if (empty($changedFields)) {
            return response()->json([
                'success' => false,
                'message' => 'No changes detected'
            ], 400);
        }

        $admin->update($updateData);

        // Log the update
        Log::info('Admin profile updated', [
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'changed_fields' => $changedFields,
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Admin profile updated successfully',
            'data' => [
                'admin' => $admin->fresh(),
                'changes_made' => $changedFields,
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollback();
        return response()->json([
            'success' => false,
            'message' => 'Failed to update admin profile',
            'error' => $e->getMessage()
        ], 500);

    }
}


/**
 * Enhanced businesses with corrected SQL queries
 */
public function getBusinessesEnhanced(Request $request)
{
    $query = Business::with(['riskTier', 'createdBy']);

    // Filters
    if ($request->filled('status')) {
        $query->where('is_active', $request->status === 'active');
    }
    if ($request->filled('high_utilization')) {
        $query->whereRaw('(credit_balance / current_balance) > 0.8');
    }
    if ($request->filled('min_credit')) {
        $query->where('current_balance', '>=', $request->min_credit);
    }
    if ($request->filled('has_debt')) {
        $query->where('credit_balance', '>', 0);
    }

    $businesses = $query->orderBy('created_at', 'desc')->paginate(20);

    // Enhanced business data with comprehensive metrics
    $businesses->getCollection()->transform(function ($business) {
        // Calculate metrics safely with qualified column names
        $totalPOs = $business->purchaseOrders()->count();

        // Use separate queries to avoid ambiguity
        $lastPOActivity = $business->purchaseOrders()->latest('created_at')->first()?->created_at;
        $lastPaymentActivity = $business->directPayments()->latest('created_at')->first()?->created_at;

        $lastActivity = null;
        if ($lastPOActivity && $lastPaymentActivity) {
            $lastActivity = max($lastPOActivity, $lastPaymentActivity);
        } elseif ($lastPOActivity) {
            $lastActivity = $lastPOActivity;
        } elseif ($lastPaymentActivity) {
            $lastActivity = $lastPaymentActivity;
        } else {
            $lastActivity = $business->updated_at;
        }

        $business->enhanced_metrics = [
            'current_balance' => $business->current_balance,
            'available_balance' => $business->available_balance,
            'credit_balance' => $business->credit_balance,
            'credit_utilization' => $business->getCreditUtilization(),
            'spending_power_utilization' => $business->getSpendingPowerUtilization(),
            'payment_score' => $business->getPaymentScore(),
            'total_pos' => $totalPOs,
            'pending_pos' => $business->purchaseOrders()
                ->where('purchase_orders.status', 'pending') // Qualified column name
                ->count(),
            'overdue_pos' => $business->purchaseOrders()->overdue()->count(),
            'pending_payments' => $business->directPayments()
                ->where('payments.status', 'pending') // Qualified column name
                ->count(),
            'total_spent' => $business->purchaseOrders()->sum('net_amount'),
            'total_repaid' => $business->directPayments()
                ->where('payments.status', 'confirmed') // Qualified column name
                ->sum('amount'),
            'last_activity' => $lastActivity?->format('Y-m-d'),
            'days_since_activity' => $lastActivity ? now()->diffInDays($lastActivity) : null,
            'effective_interest_rate' => $business->getEffectiveInterestRate(),
            'potential_monthly_interest' => $business->calculatePotentialInterest(30),
            'risk_level' => $this->calculateRiskLevel($business),
        ];

        return $business;
    });

    return response()->json([
        'success' => true,
        'message' => 'Businesses retrieved successfully',
        'data' => $businesses,
        'platform_summary' => [
            'total_businesses' => Business::count(),
            'active_businesses' => Business::where('is_active', true)->count(),
            'total_assigned_credit' => Business::sum('current_balance'),
            'total_outstanding_debt' => Business::sum('credit_balance'),
            'average_utilization' => Business::where('current_balance', '>', 0)
                ->selectRaw('AVG((credit_balance / current_balance) * 100) as avg_util')
                ->value('avg_util') ?? 0,
            'high_risk_businesses' => Business::whereRaw('(credit_balance / current_balance) > 0.8')->count(),
        ]
    ]);
}

/**
 * Get all vendors for admin review
 */
public function getVendors(Request $request)
{
    $query = Vendor::with(['business', 'approvedBy', 'rejectedBy']);

    // Filters
    if ($request->filled('status')) {
        $query->where('status', $request->status);
    }
    if ($request->filled('business_id')) {
        $query->where('business_id', $request->business_id);
    }
    if ($request->filled('category')) {
        $query->where('category', 'like', '%' . $request->category . '%');
    }

    $vendors = $query->orderBy('created_at', 'desc')->paginate(20);

    return response()->json([
        'success' => true,
        'message' => 'Vendors retrieved successfully',
        'data' => $vendors,
        'summary' => [
            'total_vendors' => Vendor::count(),
            'pending_vendors' => Vendor::pending()->count(),
            'approved_vendors' => Vendor::approved()->count(),
            'rejected_vendors' => Vendor::rejected()->count(),
        ]
    ]);
}

/**
 * Approve a vendor
 */
public function approveVendor(Request $request, Vendor $vendor)
{
    if (!$vendor->canBeApproved()) {
        return response()->json([
            'success' => false,
            'message' => 'Vendor cannot be approved. Current status: ' . $vendor->status
        ], 400);
    }

    $request->validate([
        'notes' => 'nullable|string|max:500'
    ]);

    DB::beginTransaction();
    try {
        $admin = Auth::user();

        $vendor->update([
            'status' => 'approved',
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        DB::commit();

        // Send notification to business
        try {
            Mail::to($vendor->business->email)->send(new VendorApproved($vendor, [
                'approved_by' => $admin->name,
                'approved_at' => now()->format('F j, Y g:i A'),
                'notes' => $request->notes,
            ]));

            Log::info('Vendor approval notification sent', [
                'vendor_id' => $vendor->id,
                'business_email' => $vendor->business->email,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send vendor approval notification', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vendor approved successfully',
            'data' => [
                'vendor' => $vendor->fresh(['business', 'approvedBy']),
                'approval_details' => [
                    'approved_by' => $admin->name,
                    'approved_at' => now(),
                    'notes' => $request->notes,
                ]
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollback();
        return response()->json([
            'success' => false,
            'message' => 'Failed to approve vendor',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * Reject a vendor
 */
public function rejectVendor(Request $request, Vendor $vendor)
{
    if (!$vendor->canBeRejected()) {
        return response()->json([
            'success' => false,
            'message' => 'Vendor cannot be rejected. Current status: ' . $vendor->status
        ], 400);
    }

    $request->validate([
        'rejection_reason' => 'required|string|max:1000'
    ]);

    DB::beginTransaction();
    try {
        $admin = Auth::user();

        $vendor->update([
            'status' => 'rejected',
            'rejected_by' => $admin->id,
            'rejected_at' => now(),
            'rejection_reason' => $request->rejection_reason,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        DB::commit();

        // Send notification to business
        try {
            Mail::to($vendor->business->email)->send(new VendorRejected($vendor, [
                'rejected_by' => $admin->name,
                'rejected_at' => now()->format('F j, Y g:i A'),
                'rejection_reason' => $request->rejection_reason,
            ]));

            Log::info('Vendor rejection notification sent', [
                'vendor_id' => $vendor->id,
                'business_email' => $vendor->business->email,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send vendor rejection notification', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vendor rejected successfully',
            'data' => [
                'vendor' => $vendor->fresh(['business', 'rejectedBy']),
                'rejection_details' => [
                    'rejected_by' => $admin->name,
                    'rejected_at' => now(),
                    'rejection_reason' => $request->rejection_reason,
                ]
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollback();
        return response()->json([
            'success' => false,
            'message' => 'Failed to reject vendor',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * Get vendor details for admin
 */
public function getVendorDetails(Vendor $vendor)
{
    $vendor->load(['business', 'approvedBy', 'rejectedBy', 'purchaseOrders']);

    return response()->json([
        'success' => true,
        'message' => 'Vendor details retrieved successfully',
        'data' => [
            'vendor' => $vendor,
            'approval_info' => $vendor->getApprovalInfo(),
            'purchase_orders_summary' => [
                'total_orders' => $vendor->purchaseOrders()->count(),
                'total_amount' => $vendor->purchaseOrders()->sum('net_amount'),
                'pending_orders' => $vendor->purchaseOrders()->where('status', 'pending')->count(),
                'approved_orders' => $vendor->purchaseOrders()->where('status', 'approved')->count(),
            ]
        ]
    ]);
}
}

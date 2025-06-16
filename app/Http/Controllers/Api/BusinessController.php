<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Vendor;
use App\Models\PurchaseOrder;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *     name="Business",
 *     description="Business operations - Revolving Credit System"
 * )
 */
class BusinessController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/business/dashboard",
     *     summary="Get business dashboard",
     *     tags={"Business"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Dashboard data retrieved")
     * )
     */
    public function dashboard()
    {
        $business = Auth::user();
        if(!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        // Get credit and spending metrics
        $totalAssignedCredit = $business->getTotalAssignedCredit();
        $availableSpendingPower = $business->getAvailableSpendingPower();
        $outstandingDebt = $business->getOutstandingDebt();
        $creditUtilization = $business->getCreditUtilization();
        $spendingPowerUtilization = $business->getSpendingPowerUtilization();
        $paymentScore = $business->getPaymentScore();

        $data = [
            'business_info' => $business->load('riskTier'),
            'credit_summary' => [
                'total_assigned_credit' => $totalAssignedCredit,      // current_balance
                'available_spending_power' => $availableSpendingPower, // available_balance
                'outstanding_debt' => $outstandingDebt,                // credit_balance
                'used_credit' => $totalAssignedCredit - $availableSpendingPower,
                'credit_utilization' => $creditUtilization,
                'spending_power_utilization' => $spendingPowerUtilization,
            ],
            'balances' => [
                'current_balance' => $business->current_balance,        // Total assigned credit
                'available_balance' => $business->available_balance,    // Spending power left
                'credit_balance' => $business->credit_balance,          // Outstanding debt
                'credit_limit' => $business->credit_limit,
                'total_collateral_balance' => $business->total_collateral_balance            // Same as available
            ],
            'performance_metrics' => [
                'payment_score' => $paymentScore,
                'effective_interest_rate' => $business->getEffectiveInterestRate(),
                'risk_tier' => $business->riskTier?->tier_name ?? 'Unassigned',
                'business_age_months' => $business->created_at->diffInMonths(now()),
            ],
            'statistics' => [
                'total_vendors' => $business->vendors()->count(),
                'active_vendors' => $business->vendors()->active()->count(),
                'total_purchase_orders' => $business->purchaseOrders()->count(),
                'draft_purchase_orders' => $business->purchaseOrders()->where('status', 'draft')->count(),
                'pending_purchase_orders' => $business->purchaseOrders()->where('status', 'pending')->count(),
                'approved_purchase_orders' => $business->purchaseOrders()->where('status', 'approved')->count(),
                'total_spent' => $business->purchaseOrders()->sum('net_amount'),
                'total_payments_made' => $business->payments()->where('status', 'confirmed')->sum('amount'),
                'pending_payments' => $business->payments()->where('status', 'pending')->count(),
            ],
        ];

        return response()->json([
            'success' => true,
            'message' => 'Dashboard data retrieved successfully',
            'data' => $data
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/business/vendors",
     *     summary="Create a new vendor",
     *     tags={"Business"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=201, description="Vendor created successfully")
     * )
     */
    public function createVendor(Request $request)
    {
        $business = Auth::user();
        if(!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:vendors,email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'payment_terms' => 'nullable|array',
        ]);

        $vendor = $business->vendors()->create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'category' => $request->category,
            'payment_terms' => $request->payment_terms,
            'vendor_code' => Vendor::generateVendorCode($business->id),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vendor created successfully',
            'data' => $vendor
        ], 201);
    }

    /**
     * @OA\Post(
     *     path="/api/business/purchase-orders",
     *     summary="Create purchase order (Platform pays vendor directly)",
     *     tags={"Business"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"vendor_id","order_date","net_amount"},
     *             @OA\Property(property="vendor_id", type="integer", example=1),
     *             @OA\Property(property="order_date", type="string", format="date", example="2024-01-15"),
     *             @OA\Property(property="net_amount", type="number", format="float", example=5000.00),
     *             @OA\Property(property="status", type="string", enum={"draft","pending"}, example="pending"),
     *             @OA\Property(property="description", type="string", example="Office equipment purchase")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Purchase order created - platform will pay vendor")
     * )
     */
    public function createPurchaseOrder(Request $request)
    {
        $business = Auth::user();
        if(!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'net_amount' => 'required|numeric|min:1',
            'order_date' => 'required|date|before_or_equal:today',
            'expected_delivery_date' => 'nullable|date|after:order_date',
            'description' => 'required|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'status' => 'nullable|in:draft,pending',
        ]);

        // Verify vendor belongs to business
        $vendor = $business->vendors()->findOrFail($request->vendor_id);

        $netAmount = $request->net_amount;
        $status = $request->status ?? 'pending';

        // Check available spending power for pending orders
        if ($status === 'pending') {
            if (!$business->canCreatePurchaseOrder($netAmount)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient spending power to create this purchase order',
                    'errors' => [
                        'required_amount' => $netAmount,
                        'available_spending_power' => $business->getAvailableSpendingPower(),
                        'total_assigned_credit' => $business->getTotalAssignedCredit(),
                        'current_utilization' => $business->getSpendingPowerUtilization()
                    ]
                ], 400);
            }
        }

        // Calculate due date
        $paymentTermsDays = SystemSetting::getValue('default_payment_terms_days', 30);
        $dueDate = now()->addDays($paymentTermsDays)->toDateString();

        DB::beginTransaction();
        try {
            // Create purchase order
            $purchaseOrder = PurchaseOrder::create([
                'po_number' => PurchaseOrder::generatePoNumber($business->id),
                'business_id' => $business->id,
                'vendor_id' => $vendor->id,
                'net_amount' => $netAmount,
                'outstanding_amount' => $netAmount, // Initially, full amount is outstanding
                'payment_status' => 'unpaid',
                'status' => $status,
                'order_date' => $request->order_date,
                'due_date' => $dueDate,
                'expected_delivery_date' => $request->expected_delivery_date,
                'description' => $request->description,
                'notes' => $request->notes,
            ]);

            // For pending orders, reduce spending power and create debt
            if ($status === 'pending') {
                $business->createPurchaseOrder($netAmount, $purchaseOrder->id);
            }

            DB::commit();

            $message = $status === 'draft' ?
                'Draft purchase order created successfully. Change status to pending to commit spending power.' :
                'Purchase order created successfully. Platform will pay vendor directly.';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $purchaseOrder->load(['vendor', 'business'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create purchase order',
                'errors' => ['error' => $e->getMessage()]
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/business/purchase-orders/{po}/payments",
     *     summary="Submit payment to restore spending power",
     *     tags={"Business"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="po",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"amount","receipt"},
     *                 @OA\Property(property="amount", type="number", format="float", example=1000.00),
     *                 @OA\Property(property="receipt", type="string", format="binary"),
     *                 @OA\Property(property="notes", type="string", example="Payment to restore spending power")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="Payment submitted - awaiting admin approval to restore credit")
     * )
     */
    public function submitPayment(Request $request, PurchaseOrder $po)
    {
        $business = Auth::user();
        if(!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        // Verify PO belongs to business
        if ($po->business_id !== $business->id) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found or access denied'
            ], 404);
        }

        $maxPayment = min($po->outstanding_amount, $business->getMaxPaymentAmount());

        $request->validate([
            'amount' => 'required|numeric|min:1|max:' . $maxPayment,
            'receipt' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB max
            'notes' => 'nullable|string|max:500'
        ]);

        // Store receipt file
        $receiptPath = $request->file('receipt')->store('receipts/' . $po->business_id, 'private');

        DB::beginTransaction();
        try {
            $payment = Payment::create([
                'payment_reference' => $this->generatePaymentReference(),
                'purchase_order_id' => $po->id,
                'business_id' => $po->business_id,
                'amount' => $request->amount,
                'payment_type' => 'business_payment',
                'status' => 'pending',
                'receipt_path' => $receiptPath,
                'notes' => $request->notes,
                'payment_date' => now()
            ]);

            // Just log the submission - no balance changes until admin approves
            $business->submitPayment($request->amount, $payment->id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment submitted successfully. Admin approval will restore your spending power.',
                'data' => [
                    'payment' => $payment,
                    'current_debt' => $business->getOutstandingDebt(),
                    'potential_restored_credit' => $request->amount,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/business/purchase-orders/{po}/payments",
     *     summary="Get payment history for purchase order",
     *     tags={"Business"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Payment history retrieved")
     * )
     */
    public function getPaymentHistory(PurchaseOrder $po)
    {
        $business = Auth::user();
        if(!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        // Verify PO belongs to business
        if ($po->business_id !== $business->id) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found or access denied'
            ], 404);
        }

        $payments = $po->payments()->with('confirmedBy')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'message' => 'Payment history retrieved successfully',
            'data' => [
                'purchase_order' => $po->load('vendor'),
                'payments' => $payments,
                'summary' => [
                    'total_amount' => $po->net_amount,
                    'total_paid' => $po->total_paid_amount,
                    'outstanding_amount' => $po->outstanding_amount,
                    'payment_status' => $po->payment_status,
                    'payments_count' => $payments->count(),
                    'confirmed_payments' => $payments->where('status', 'confirmed')->count(),
                    'pending_payments' => $payments->where('status', 'pending')->count(),
                    'potential_credit_restoration' => $payments->where('status', 'pending')->sum('amount'),
                ]
            ]
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/business/credit-status",
     *     summary="Get credit and spending power status",
     *     tags={"Business"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Credit status retrieved")
     * )
     */
    public function getCreditStatus()
    {
        $business = Auth::user();
        if(!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $totalAssignedCredit = $business->getTotalAssignedCredit();
        $availableSpendingPower = $business->getAvailableSpendingPower();
        $outstandingDebt = $business->getOutstandingDebt();
        $creditUtilization = $business->getCreditUtilization();
        $spendingPowerUtilization = $business->getSpendingPowerUtilization();
        $paymentScore = $business->getPaymentScore();

        // Calculate potential interest if applicable
        $interestRate = $business->getEffectiveInterestRate();
        $potentialMonthlyInterest = $business->calculatePotentialInterest(30);

        return response()->json([
            'success' => true,
            'message' => 'Credit status retrieved successfully',
            'data' => [
                'credit_overview' => [
                    'total_assigned_credit' => $totalAssignedCredit,      // What admin assigned
                    'available_spending_power' => $availableSpendingPower, // What you can still spend
                    'used_credit' => $totalAssignedCredit - $availableSpendingPower,
                    'outstanding_debt' => $outstandingDebt,                // What you owe
                ],
                'utilization_metrics' => [
                    'credit_utilization' => $creditUtilization,           // Debt vs assigned credit
                    'spending_power_utilization' => $spendingPowerUtilization, // Used vs available
                ],
                'performance_metrics' => [
                    'payment_score' => $paymentScore,
                    'average_payment_time' => $business->getAveragePaymentTime(),
                    'business_age_months' => $business->created_at->diffInMonths(now()),
                ],
                'interest_information' => [
                    'effective_interest_rate' => $interestRate,
                    'interest_applicable' => $interestRate > 0,
                    'potential_monthly_interest' => $potentialMonthlyInterest,
                    'risk_tier' => $business->riskTier?->tier_name ?? 'Unassigned',
                ],
                'balance_details' => [
                    'current_balance' => $business->current_balance,       // Total assigned credit
                    'available_balance' => $business->available_balance,   // Spending power left
                    'credit_balance' => $business->credit_balance,         // Outstanding debt
                    'credit_limit' => $business->credit_limit,             // Same as available
                ],
                'next_actions' => [
                    'can_create_po' => $availableSpendingPower > 0,
                    'max_po_amount' => $availableSpendingPower,
                    'should_make_payment' => $outstandingDebt > 0,
                    'max_payment_amount' => $business->getMaxPaymentAmount(),
                ]
            ]
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/business/spending-analysis",
     *     summary="Get detailed spending and debt analysis",
     *     tags={"Business"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Spending analysis retrieved")
     * )
     */
    public function getSpendingAnalysis()
    {
        $business = Auth::user();
        if(!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $outstandingOrders = $business->purchaseOrders()
            ->whereIn('payment_status', ['unpaid', 'partially_paid'])
            ->with(['vendor', 'payments' => function($query) {
                $query->where('status', 'confirmed');
            }])
            ->orderBy('order_date', 'desc')
            ->get();

        $spendingBreakdown = [];
        $totalPrincipal = 0;
        $potentialInterest = 0;

        foreach ($outstandingOrders as $order) {
            $daysSinceOrder = now()->diffInDays($order->order_date);
            $isOverdue = $order->due_date && now()->gt($order->due_date);
            $daysOverdue = $isOverdue ? now()->diffInDays($order->due_date) : 0;

            // Calculate potential interest if applicable
            $interestRate = $business->getEffectiveInterestRate();
            $orderInterest = 0;
            if ($interestRate > 0) {
                $dailyRate = $interestRate / 365 / 100;
                $orderInterest = round($order->outstanding_amount * $dailyRate * $daysSinceOrder, 2);
            }

            $totalPrincipal += $order->outstanding_amount;
            $potentialInterest += $orderInterest;

            $spendingBreakdown[] = [
                'po_number' => $order->po_number,
                'vendor_name' => $order->vendor->name,
                'order_date' => $order->order_date->format('Y-m-d'),
                'due_date' => $order->due_date,
                'description' => $order->description ?? 'No description',
                'net_amount' => $order->net_amount,
                'paid_amount' => $order->total_paid_amount,
                'outstanding_amount' => $order->outstanding_amount,
                'days_since_order' => $daysSinceOrder,
                'is_overdue' => $isOverdue,
                'days_overdue' => $daysOverdue,
                'potential_interest' => $orderInterest,
                'payment_status' => $order->payment_status,
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Spending analysis retrieved successfully',
            'data' => [
                'summary' => [
                    'total_outstanding_debt' => $totalPrincipal,
                    'potential_interest_if_applicable' => $potentialInterest,
                    'total_orders_outstanding' => count($spendingBreakdown),
                    'overdue_orders' => collect($spendingBreakdown)->where('is_overdue', true)->count(),
                    'average_order_age' => collect($spendingBreakdown)->avg('days_since_order'),
                ],
                'spending_breakdown' => $spendingBreakdown,
                'interest_info' => [
                    'current_rate' => $business->getEffectiveInterestRate(),
                    'interest_applicable' => $business->getEffectiveInterestRate() > 0,
                    'rate_source' => $business->custom_interest_rate ? 'Custom Rate' :
                                    ($business->riskTier ? 'Risk Tier' : 'System Default'),
                ]
            ]
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/business/payments/pending",
     *     summary="Get pending payments awaiting admin approval",
     *     tags={"Business"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Pending payments retrieved")
     * )
     */
    public function getPendingPayments()
    {
        $business = Auth::user();
        if(!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $pendingPayments = $business->payments()
            ->where('status', 'pending')
            ->with(['purchaseOrder.vendor'])
            ->orderBy('created_at', 'desc')
            ->get();

        $totalPendingAmount = $pendingPayments->sum('amount');

        return response()->json([
            'success' => true,
            'message' => 'Pending payments retrieved successfully',
            'data' => [
                'pending_payments' => $pendingPayments,
                'summary' => [
                    'total_pending_payments' => $pendingPayments->count(),
                    'total_pending_amount' => $totalPendingAmount,
                    'potential_credit_restoration' => $totalPendingAmount,
                    'current_available_spending' => $business->getAvailableSpendingPower(),
                    'spending_after_approval' => $business->getAvailableSpendingPower() + $totalPendingAmount,
                ]
            ]
        ]);
    }

    /**
     * Generate unique payment reference
     */
    private function generatePaymentReference(): string
    {
        $prefix = 'PAY';
        $year = date('Y');
        $month = date('m');

        // Generate random suffix
        $suffix = strtoupper(Str::random(6));

        // Ensure uniqueness
        do {
            $reference = $prefix . $year . $month . $suffix;
            $suffix = strtoupper(Str::random(6));
        } while (Payment::where('payment_reference', $reference)->exists());

        return $reference;
    }

    /**
     * @OA\Get(
     *     path="/api/business/vendors",
     *     summary="Get business vendors",
     *     tags={"Business"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Vendors retrieved")
     * )
     */
    public function getVendors()
    {
        $business = Auth::user();
        if(!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $vendors = $business->vendors()
            ->withCount('purchaseOrders')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Vendors retrieved successfully',
            'data' => $vendors
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/business/purchase-orders",
     *     summary="Get business purchase orders",
     *     tags={"Business"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Purchase orders retrieved")
     * )
     */
    public function getPurchaseOrders(Request $request)
    {
        $business = Auth::user();
        if(!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $query = $business->purchaseOrders()->with(['vendor', 'payments']);

        // Filter by status if provided
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by payment status if provided
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        $purchaseOrders = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json([
            'success' => true,
            'message' => 'Purchase orders retrieved successfully',
            'data' => $purchaseOrders
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/business/spending-suggestions",
     *     summary="Get spending suggestions based on available credit",
     *     tags={"Business"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Spending suggestions retrieved")
     * )
     */
    public function getSpendingSuggestions()
    {
        $business = Auth::user();
        if(!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $availableSpending = $business->getAvailableSpendingPower();
        $outstandingDebt = $business->getOutstandingDebt();
        $paymentScore = $business->getPaymentScore();

        $suggestions = [];

        // Spending suggestions
        if ($availableSpending > 0) {
            $suggestions[] = [
                'type' => 'spending_opportunity',
                'title' => 'Available Spending Power',
                'message' => "You have $" . number_format($availableSpending, 2) . " available for new purchase orders.",
                'action' => 'Create new PO',
                'priority' => 'info'
            ];
        } else {
            $suggestions[] = [
                'type' => 'no_spending_power',
                'title' => 'No Spending Power',
                'message' => 'Make payments to restore your spending power.',
                'action' => 'Submit payment',
                'priority' => 'warning'
            ];
        }

        // Payment suggestions
        if ($outstandingDebt > 0) {
            $urgency = $outstandingDebt > ($business->getTotalAssignedCredit() * 0.8) ? 'high' : 'medium';
            $suggestions[] = [
                'type' => 'payment_suggestion',
                'title' => 'Outstanding Debt',
                'message' => "You have $" . number_format($outstandingDebt, 2) . " in outstanding debt.",
                'action' => 'Make payment to restore credit',
                'priority' => $urgency === 'high' ? 'danger' : 'warning'
            ];
        }

        // Performance suggestions
        if ($paymentScore < 80 && $paymentScore > 0) {
            $suggestions[] = [
                'type' => 'performance_improvement',
                'title' => 'Improve Payment Score',
                'message' => "Your payment score is {$paymentScore}%. Consistent payments can improve your terms.",
                'action' => 'Make timely payments',
                'priority' => 'info'
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Spending suggestions retrieved successfully',
            'data' => [
                'suggestions' => $suggestions,
                'current_status' => [
                    'available_spending' => $availableSpending,
                    'outstanding_debt' => $outstandingDebt,
                    'payment_score' => $paymentScore,
                    'utilization' => $business->getSpendingPowerUtilization(),
                ]
            ]
        ]);
    }



/**
 * @OA\Get(
 *     path="/api/business/purchase-tracker",
 *     summary="Get purchase order analytics and spending insights",
 *     tags={"Business"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Purchase tracker data retrieved")
 * )
 */
public function getPurchaseTracker(Request $request)
{
    $business = Auth::user();
    if(!$business || !($business instanceof Business)) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized - Business access required'
        ], 403);
    }

    // Get year for analysis (default current year)
    $year = $request->input('year', now()->year);
    $startDate = "{$year}-01-01";
    $endDate = "{$year}-12-31";

    // Current month calculations
    $currentMonth = now()->format('Y-m');
    $lastMonth = now()->subMonth()->format('Y-m');

    // Current month summary
    $currentMonthPOs = $business->purchaseOrders()
        ->whereYear('order_date', now()->year)
        ->whereMonth('order_date', now()->month)
        ->get();

    $currentMonthSpend = $currentMonthPOs->sum('net_amount');
    $currentMonthCount = $currentMonthPOs->count();

    // Last month for comparison
    $lastMonthPOs = $business->purchaseOrders()
        ->whereYear('order_date', now()->subMonth()->year)
        ->whereMonth('order_date', now()->subMonth()->month)
        ->get();

    $lastMonthSpend = $lastMonthPOs->sum('net_amount');

    // Calculate percentage change
    $changeFromLastMonth = 0;
    if ($lastMonthSpend > 0) {
        $changeFromLastMonth = round((($currentMonthSpend - $lastMonthSpend) / $lastMonthSpend) * 100, 1);
    }

    // Top vendor for current month
    $topVendorCurrentMonth = $currentMonthPOs->groupBy('vendor_id')
        ->map(function ($pos) {
            return [
                'vendor' => $pos->first()->vendor,
                'total_spent' => $pos->sum('net_amount'),
                'po_count' => $pos->count()
            ];
        })
        ->sortByDesc('total_spent')
        ->first();

    // YTD (Year to Date) summary
    $ytdPOs = $business->purchaseOrders()
        ->whereYear('order_date', now()->year)
        ->get();

    $ytdTotalSpend = $ytdPOs->sum('net_amount');
    $ytdTotalCount = $ytdPOs->count();

    // Monthly breakdown for the year
    $monthlyBreakdown = [];

    for ($month = 1; $month <= 12; $month++) {
        $monthDate = sprintf('%s-%02d', $year, $month);
        $monthName = date('M Y', strtotime($monthDate . '-01'));

        $monthPOs = $business->purchaseOrders()
            ->whereYear('order_date', $year)
            ->whereMonth('order_date', $month)
            ->with('vendor')
            ->get();

        $monthTotalValue = $monthPOs->sum('net_amount');
        $monthPOCount = $monthPOs->count();

        // Top vendor for this month
        $topVendorMonth = null;
        if ($monthPOs->isNotEmpty()) {
            $vendorSpending = $monthPOs->groupBy('vendor_id')
                ->map(function ($pos) {
                    return [
                        'vendor_name' => $pos->first()->vendor->name ?? 'Unknown',
                        'total_spent' => $pos->sum('net_amount'),
                        'po_count' => $pos->count()
                    ];
                })
                ->sortByDesc('total_spent')
                ->first();

            $topVendorMonth = $vendorSpending['vendor_name'] ?? null;
        }

        $monthlyBreakdown[] = [
            'month' => $monthDate,
            'month_name' => $monthName,
            'total_value' => $monthTotalValue,
            'number_of_pos' => $monthPOCount,
            'top_vendor' => $topVendorMonth,
        ];
    }

    // Vendor performance analysis
    $vendorPerformance = $business->purchaseOrders()
        ->whereYear('order_date', $year)
        ->with('vendor')
        ->get()
        ->groupBy('vendor_id')
        ->map(function ($pos) {
            $vendor = $pos->first()->vendor;
            return [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->name,
                'total_spent' => $pos->sum('net_amount'),
                'po_count' => $pos->count(),
                'avg_po_value' => $pos->avg('net_amount'),
                'first_po_date' => $pos->min('order_date'),
                'last_po_date' => $pos->max('order_date'),
            ];
        })
        ->sortByDesc('total_spent')
        ->values()
        ->take(10); // Top 10 vendors

    // Spending trends analysis
    $spendingTrends = [
        'highest_month' => collect($monthlyBreakdown)->sortByDesc('total_value')->first(),
        'lowest_month' => collect($monthlyBreakdown)->where('total_value', '>', 0)->sortBy('total_value')->first(),
        'avg_monthly_spend' => collect($monthlyBreakdown)->avg('total_value'),
        'most_active_month' => collect($monthlyBreakdown)->sortByDesc('number_of_pos')->first(),
    ];

    // Quarter analysis
    $quarterlyAnalysis = [
        'Q1' => collect($monthlyBreakdown)->slice(0, 3)->sum('total_value'),
        'Q2' => collect($monthlyBreakdown)->slice(3, 3)->sum('total_value'),
        'Q3' => collect($monthlyBreakdown)->slice(6, 3)->sum('total_value'),
        'Q4' => collect($monthlyBreakdown)->slice(9, 3)->sum('total_value'),
    ];

    // Payment performance integration
    $paymentMetrics = [
        'total_payments_made' => $business->payments()->where('status', 'confirmed')
            ->whereYear('confirmed_at', $year)
            ->sum('amount'),
        'pending_payments' => $business->payments()->where('status', 'pending')->sum('amount'),
        'payment_score' => $business->getPaymentScore(),
        'avg_payment_time' => $business->getAveragePaymentTime(),
    ];

    return response()->json([
        'success' => true,
        'message' => 'Purchase tracker data retrieved successfully',
        'data' => [
            'current_month_summary' => [
                'total_spend' => $currentMonthSpend,
                'change_from_last_month' => $changeFromLastMonth,
                'po_count' => $currentMonthCount,
                'top_vendor' => $topVendorCurrentMonth['vendor']->name ?? null,
                'top_vendor_spend' => $topVendorCurrentMonth['total_spent'] ?? 0,
            ],
            'ytd_summary' => [
                'total_spend' => $ytdTotalSpend,
                'total_pos' => $ytdTotalCount,
                'avg_po_value' => $ytdTotalCount > 0 ? round($ytdTotalSpend / $ytdTotalCount, 2) : 0,
            ],
            'monthly_breakdown' => $monthlyBreakdown,
            'vendor_performance' => $vendorPerformance,
            'spending_trends' => $spendingTrends,
            'quarterly_analysis' => $quarterlyAnalysis,
            'payment_metrics' => $paymentMetrics,
            'analysis_period' => [
                'year' => $year,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'current_month' => now()->format('M Y'),
            ],
            'business_context' => [
                'credit_utilization' => $business->getCreditUtilization(),
                'available_spending_power' => $business->getAvailableSpendingPower(),
                'payment_score' => $business->getPaymentScore(),
            ]
        ]
    ]);
}

/**
 * Get purchase trends comparison (year over year)
 */
public function getPurchaseTrends(Request $request)
{
    $business = Auth::user();
    if(!$business || !($business instanceof Business)) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized - Business access required'
        ], 403);
    }

    $currentYear = $request->input('year', now()->year);
    $previousYear = $currentYear - 1;

    // Get data for both years
    $currentYearData = $business->purchaseOrders()
        ->whereYear('order_date', $currentYear)
        ->selectRaw('MONTH(order_date) as month, SUM(net_amount) as total_spend, COUNT(*) as po_count')
        ->groupBy('month')
        ->get()
        ->keyBy('month');

    $previousYearData = $business->purchaseOrders()
        ->whereYear('order_date', $previousYear)
        ->selectRaw('MONTH(order_date) as month, SUM(net_amount) as total_spend, COUNT(*) as po_count')
        ->groupBy('month')
        ->get()
        ->keyBy('month');

    // Build comparison data
    $comparison = [];
    for ($month = 1; $month <= 12; $month++) {
        $currentMonth = $currentYearData->get($month);
        $previousMonth = $previousYearData->get($month);

        $currentSpend = $currentMonth ? $currentMonth->total_spend : 0;
        $previousSpend = $previousMonth ? $previousMonth->total_spend : 0;

        $changePercent = 0;
        if ($previousSpend > 0) {
            $changePercent = round((($currentSpend - $previousSpend) / $previousSpend) * 100, 1);
        }

        $comparison[] = [
            'month' => $month,
            'month_name' => date('M', mktime(0, 0, 0, $month, 1)),
            'current_year_spend' => $currentSpend,
            'previous_year_spend' => $previousSpend,
            'change_percent' => $changePercent,
            'current_year_pos' => $currentMonth ? $currentMonth->po_count : 0,
            'previous_year_pos' => $previousMonth ? $previousMonth->po_count : 0,
        ];
    }

    return response()->json([
        'success' => true,
        'message' => 'Purchase trends comparison retrieved successfully',
        'data' => [
            'year_comparison' => [
                'current_year' => $currentYear,
                'previous_year' => $previousYear,
            ],
            'monthly_comparison' => $comparison,
            'summary' => [
                'current_year_total' => $currentYearData->sum('total_spend'),
                'previous_year_total' => $previousYearData->sum('total_spend'),
                'yoy_growth' => $previousYearData->sum('total_spend') > 0 ?
                    round((($currentYearData->sum('total_spend') - $previousYearData->sum('total_spend')) / $previousYearData->sum('total_spend')) * 100, 1) : 0,
            ]
        ]
    ]);
}



/**
 * @OA\Get(
 *     path="/api/business/profile",
 *     summary="Get business profile information",
 *     tags={"Business"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\Response(response=200, description="Business profile retrieved")
 * )
 */
public function getProfile()
{
    $business = Auth::user();
    if(!$business || !($business instanceof Business)) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized - Business access required'
        ], 403);
    }

    $business->load(['riskTier', 'createdBy']);

    return response()->json([
        'success' => true,
        'message' => 'Business profile retrieved successfully',
        'data' => [
            'business_info' => [
                'id' => $business->id,
                'name' => $business->name,
                'email' => $business->email, // Read-only for security
                'phone' => $business->phone,
                'address' => $business->address,
                'business_type' => $business->business_type,
                'registration_number' => $business->registration_number,
                'is_active' => $business->is_active,
                'created_at' => $business->created_at,
                'last_login_at' => $business->last_login_at,
            ],
            'account_status' => [
                'risk_tier' => $business->riskTier?->tier_name ?? 'Standard',
                'account_age_days' => $business->created_at->diffInDays(now()),
                'last_activity' => $business->getDaysSinceLastActivity(),
                'created_by' => $business->createdBy?->name ?? 'System',
            ],
            'financial_summary' => [
                'total_assigned_credit' => $business->getTotalAssignedCredit(),
                'available_spending_power' => $business->getAvailableSpendingPower(),
                'outstanding_debt' => $business->getOutstandingDebt(),
                'credit_utilization' => $business->getCreditUtilization(),
                'payment_score' => $business->getPaymentScore(),
            ],
            'activity_summary' => $business->getActivitySummary(30),
        ]
    ]);
}

/**
 * @OA\Put(
 *     path="/api/business/profile",
 *     summary="Update business profile information",
 *     tags={"Business"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             @OA\Property(property="name", type="string", maxLength=255),
 *             @OA\Property(property="phone", type="string", maxLength=20),
 *             @OA\Property(property="address", type="string"),
 *             @OA\Property(property="business_type", type="string", maxLength=100)
 *         )
 *     ),
 *     @OA\Response(response=200, description="Business profile updated")
 * )
 */
public function updateProfile(Request $request)
{
    $business = Auth::user();
    if(!$business || !($business instanceof Business)) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized - Business access required'
        ], 403);
    }

    $request->validate([
        'name' => 'string|max:255',
        'phone' => 'nullable|string|max:20|regex:/^[\+\-\(\)\s\d]+$/',
        'address' => 'nullable|string|max:500',
        'business_type' => 'nullable|string|max:100',
        'registration_number' => 'nullable|string|max:50|unique:businesses,registration_number,' . $business->id,
    ]);

    // Track changes for audit
    $originalData = $business->only(['name', 'phone', 'address', 'business_type', 'registration_number']);
    $changedFields = [];

    DB::beginTransaction();
    try {
        // Update only provided fields
        $updateData = array_filter($request->only(['name', 'phone', 'address', 'business_type', 'registration_number']),
            function($value) { return !is_null($value); });

        // Track what changed
        foreach ($updateData as $field => $newValue) {
            if ($business->$field !== $newValue) {
                $changedFields[$field] = [
                    'old' => $business->$field,
                    'new' => $newValue
                ];
            }
        }

        if (empty($changedFields)) {
            return response()->json([
                'success' => false,
                'message' => 'No changes detected in the submitted data'
            ], 400);
        }

        $business->update($updateData);

        // Log the profile update
        Log::info('Business profile updated', [
            'business_id' => $business->id,
            'business_name' => $business->name,
            'changed_fields' => $changedFields,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Business profile updated successfully',
            'data' => [
                'business' => $business->fresh(['riskTier', 'createdBy']),
                'changes_made' => [
                    'fields_updated' => array_keys($changedFields),
                    'total_changes' => count($changedFields),
                    'changed_fields' => $changedFields,
                ],
                'security_notice' => 'Profile changes have been logged for security purposes'
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollback();
        return response()->json([
            'success' => false,
            'message' => 'Failed to update business profile',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * @OA\Post(
 *     path="/api/business/profile/upload-logo",
 *     summary="Upload business logo",
 *     tags={"Business"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\MediaType(
 *             mediaType="multipart/form-data",
 *             @OA\Schema(
 *                 @OA\Property(property="logo", type="string", format="binary")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=200, description="Logo uploaded successfully")
 * )
 */
public function uploadLogo(Request $request)
{
    $business = Auth::user();
    if(!$business || !($business instanceof Business)) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized - Business access required'
        ], 403);
    }

    $request->validate([
        'logo' => 'required|image|mimes:jpeg,jpg,png,gif,svg|max:2048', // 2MB max
    ]);

    DB::beginTransaction();
    try {
        // Delete old logo if exists
        if ($business->logo_path && Storage::disk('public')->exists($business->logo_path)) {
            Storage::disk('public')->delete($business->logo_path);
        }

        // Store new logo
        $logoPath = $request->file('logo')->store('business_logos', 'public');

        $business->update(['logo_path' => $logoPath]);

        // Log the logo upload
        Log::info('Business logo uploaded', [
            'business_id' => $business->id,
            'business_name' => $business->name,
            'logo_path' => $logoPath,
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Business logo uploaded successfully',
            'data' => [
                'logo_path' => $logoPath,
                'logo_url' => asset('storage/' . $logoPath),
                'file_size' => $request->file('logo')->getSize(),
                'file_type' => $request->file('logo')->getMimeType(),
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollback();
        return response()->json([
            'success' => false,
            'message' => 'Failed to upload logo',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * Get specific purchase order for business
 */
public function getPurchaseOrder(PurchaseOrder $po)
{
    $business = Auth::user();
    if(!$business || !($business instanceof Business)) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized - Business access required'
        ], 403);
    }

    // Verify PO belongs to business
    if ($po->business_id !== $business->id) {
        return response()->json([
            'success' => false,
            'message' => 'Purchase order not found or access denied'
        ], 404);
    }

    $po->load(['vendor', 'payments.confirmedBy', 'payments.rejectedBy']);

    return response()->json([
        'success' => true,
        'message' => 'Purchase order retrieved successfully',
        'data' => [
            'purchase_order' => $po,
            'calculated_metrics' => [
                'days_since_order' => $po->getDaysSinceOrder(),
                'is_overdue' => $po->isOverdue(),
                'days_overdue' => $po->getDaysOverdue(),
                'payment_progress' => $po->getPaymentProgress(),
                'urgency_level' => $po->getUrgencyLevel(),
            ],
            'payment_suggestions' => $po->getPaymentSuggestions(),
        ]
    ]);
}

/**
 * Download payment receipt
 */
public function downloadReceipt(Payment $payment)
{
    $business = Auth::user();

    // Check authorization
    if(!$business || !($business instanceof Business) || $payment->business_id !== $business->id) {
        abort(403, 'Unauthorized access to receipt');
    }

    if (!$payment->receipt_path || !Storage::disk('private')->exists($payment->receipt_path)) {
        abort(404, 'Receipt file not found');
    }

    return Storage::disk('private')->download($payment->receipt_path, 'receipt_' . $payment->payment_reference . '.pdf');
}
}

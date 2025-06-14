<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Vendor;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *     name="Business",
 *     description="Business operations"
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

        $data = [
            'business_info' => $business,
            'balances' => [
                'available_balance' => $business->available_balance,
                'current_balance' => $business->current_balance,
                'credit_balance' => $business->credit_balance,
                'treasury_collateral_balance' => $business->treasury_collateral_balance,
                'credit_limit' => $business->credit_limit,
            ],
            'statistics' => [
                'total_vendors' => $business->vendors()->count(),
                'active_vendors' => $business->vendors()->active()->count(),
                'total_purchase_orders' => $business->purchaseOrders()->count(),
                'draft_purchase_orders' => $business->purchaseOrders()->where('status', 'draft')->count(),
                'pending_purchase_orders' => $business->purchaseOrders()->where('status', 'pending')->count(),
                'approved_purchase_orders' => $business->purchaseOrders()->where('status', 'approved')->count(),
                'total_po_amount' => $business->purchaseOrders()->sum('net_amount'),
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
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","email"},
     *             @OA\Property(property="name", type="string", example="Supplier ABC"),
     *             @OA\Property(property="email", type="string", format="email", example="supplier@abc.com"),
     *             @OA\Property(property="category", type="string", example="Office Supplies")
     *         )
     *     ),
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
     *     summary="Create purchase order",
     *     tags={"Business"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"vendor_id","order_date"},
     *             @OA\Property(property="vendor_id", type="integer", example=1),
     *             @OA\Property(property="order_date", type="string", format="date", example="2024-01-15"),
     *             @OA\Property(property="status", type="string", enum={"draft","pending"}, example="draft"),
     *             @OA\Property(property="items", type="array", @OA\Items(
     *                 @OA\Property(property="description", type="string", example="Office supplies"),
     *                 @OA\Property(property="quantity", type="number", example=10),
     *                 @OA\Property(property="unit_price", type="number", format="float", example=25.50)
     *             ))
     *         )
     *     ),
     *     @OA\Response(response=201, description="Purchase order created")
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
            'items' => 'nullable|array|max:50',
            'items.*.description' => 'required_with:items|string|max:255',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01|max:999999',
            'items.*.unit_price' => 'required_with:items|numeric|min:0|max:9999999999.99',
            'order_date' => 'required|date|before_or_equal:today',
            'expected_delivery_date' => 'nullable|date|after:order_date',
            'notes' => 'nullable|string|max:1000',
            'tax_amount' => 'nullable|numeric|min:0|max:9999999999.99',
            'discount_amount' => 'nullable|numeric|min:0|max:9999999999.99',
            'status' => 'nullable|in:draft,pending',
        ]);

        // Verify vendor belongs to business
        $vendor = $business->vendors()->findOrFail($request->vendor_id);

        // Calculate totals
        $totalAmount = 0;
        $items = [];

        // Only calculate amounts if items are provided
        if ($request->filled('items') && is_array($request->items)) {
            foreach ($request->items as $item) {
                $lineTotal = $item['quantity'] * $item['unit_price'];
                $totalAmount += $lineTotal;

                $items[] = [
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                ];
            }
        }

        $taxAmount = $request->tax_amount ?? 0;
        $discountAmount = $request->discount_amount ?? 0;
        $netAmount = $totalAmount + $taxAmount - $discountAmount;
        $status = $request->status ?? 'draft';

        // Only check balance if status is pending and has items with amount
        if ($status === 'pending' && $netAmount > 0) {
            if (!$business->canCreatePurchaseOrder($netAmount)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient available balance to create this purchase order',
                    'errors' => [
                        'required_amount' => $netAmount,
                        'available_balance' => $business->available_balance
                    ]
                ], 400);
            }
        }

        DB::beginTransaction();
        try {
            // Create purchase order
            $purchaseOrder = PurchaseOrder::create([
                'po_number' => PurchaseOrder::generatePoNumber($business->id),
                'business_id' => $business->id,
                'vendor_id' => $vendor->id,
                'total_amount' => $totalAmount,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'net_amount' => $netAmount,
                'status' => $status,
                'order_date' => $request->order_date,
                'expected_delivery_date' => $request->expected_delivery_date,
                'notes' => $request->notes,
                'items' => empty($items) ? null : $items,
            ]);

            // Only deduct balance if status is pending and has amount
            if ($status === 'pending' && $netAmount > 0) {
                // Deduct from available balance
                $business->updateBalance('available', $netAmount, 'subtract');
            }

            DB::commit();

            $message = $status === 'draft' ?
                'Draft purchase order created successfully. Add items and change status to pending when ready.' :
                'Purchase order created successfully';

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
}

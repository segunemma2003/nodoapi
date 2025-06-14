<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Business;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Mail\BusinessCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *     name="Admin",
 *     description="Admin operations"
 * )
 */
class AdminController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/admin/dashboard",
     *     summary="Get admin dashboard data",
     *     tags={"Admin"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Dashboard data retrieved")
     * )
     */
    public function dashboard()
    {
        $data = [
            'total_businesses' => Business::count(),
            'active_businesses' => Business::active()->count(),
            'total_vendors' => Vendor::count(),
            'active_vendors' => Vendor::active()->count(),
            'total_purchase_orders' => PurchaseOrder::count(),
            'draft_purchase_orders' => PurchaseOrder::where('status', 'draft')->count(),
            'pending_purchase_orders' => PurchaseOrder::where('status', 'pending')->count(),
            'approved_purchase_orders' => PurchaseOrder::where('status', 'approved')->count(),
            'total_business_value' => Business::sum('available_balance'),
            'recent_businesses' => Business::with('createdBy')->latest()->take(5)->get(),
            'recent_purchase_orders' => PurchaseOrder::with(['business', 'vendor'])
                ->latest()->take(10)->get(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Dashboard data retrieved successfully',
            'data' => $data
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/admin/businesses",
     *     summary="Create a new business",
     *     tags={"Admin"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","email","business_type"},
     *             @OA\Property(property="name", type="string", example="Tech Company"),
     *             @OA\Property(property="email", type="string", format="email", example="tech@company.com"),
     *             @OA\Property(property="business_type", type="string", example="Technology"),
     *             @OA\Property(property="available_balance", type="number", format="float", example=50000)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Business created successfully")
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
            'credit_limit' => 'nullable|numeric|min:0',
            'available_balance' => 'nullable|numeric|min:0',
        ]);

        // Generate random password
        $password = Str::random(12);

        $business = Business::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'business_type' => $request->business_type,
            'registration_number' => $request->registration_number,
            'password' => $password,
            'credit_limit' => $request->credit_limit ?? 0,
            'available_balance' => $request->available_balance ?? 0,
            'current_balance' => $request->available_balance ?? 0,
            'created_by' => Auth::id(),
        ]);

        // Send credentials email
        try {
            Mail::to($business->email)->send(new BusinessCredentials($business, $password));
        } catch (\Exception $e) {
            Log::error('Failed to send credentials email: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Business created successfully and credentials sent via email',
            'data' => $business->load('createdBy')
        ], 201);
    }
}

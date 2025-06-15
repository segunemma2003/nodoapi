<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Business;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * @OA\Tag(
 *     name="Authentication",
 *     description="Authentication endpoints with Sanctum"
 * )
 */
class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/auth/admin/login",
     *     summary="Admin login",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Login successful"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", type="object"),
     *                 @OA\Property(property="token", type="string"),
     *                 @OA\Property(property="token_type", type="string", example="Bearer"),
     *                 @OA\Property(property="abilities", type="array", @OA\Items(type="string"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Invalid credentials")
     * )
     */
    public function adminLogin(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        $user = User::where('email', $request->email)
                   ->where('user_type', 'admin')
                   ->where('is_active', true)
                   ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Revoke existing tokens
        $user->tokens()->delete();

        // Create new token with admin abilities
        $token = $user->createToken('admin-token', ['admin:*'])->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user,
                'token' => $token,
                'token_type' => 'Bearer',
                'abilities' => ['admin:*']
            ]
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/auth/business/login",
     *     summary="Business login",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email", example="business@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password123")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Login successful"),
     *     @OA\Response(response=422, description="Invalid credentials")
     * )
     */
    public function businessLogin(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        $business = Business::where('email', $request->email)->where('is_active', true)->first();

        if (!$business || !Hash::check($request->password, $business->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Update last login
        $business->update(['last_login_at' => now()]);

        // Revoke existing tokens
        $business->tokens()->delete();

        // Create new token with business abilities
        $token = $business->createToken('business-token', ['business:*'])->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $business,
                'token' => $token,
                'token_type' => 'Bearer',
                'abilities' => ['business:*']
            ]
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/auth/logout",
     *     summary="Logout user",
     *     tags={"Authentication"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Logout successful")
     * )
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout successful'
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/auth/me",
     *     summary="Get authenticated user",
     *     tags={"Authentication"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="User data retrieved")
     * )
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $tokenAbilities = $request->user()->currentAccessToken()->abilities;

        $userType = 'business'; // default
        if ($user instanceof User && $user->isAdmin()) {
            $userType = 'admin';
        }

        return response()->json([
            'success' => true,
            'message' => 'User data retrieved successfully',
            'data' => [
                'user' => $user,
                'user_type' => $userType,
                'abilities' => $tokenAbilities
            ]
        ]);
    }

    /**
 * @OA\Post(
 *     path="/api/auth/change-password",
 *     summary="Change password for authenticated user (Admin or Business)",
 *     tags={"Authentication"},
 *     security={{"sanctumAuth":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"current_password","new_password","new_password_confirmation"},
 *             @OA\Property(property="current_password", type="string", format="password"),
 *             @OA\Property(property="new_password", type="string", format="password", minLength=8),
 *             @OA\Property(property="new_password_confirmation", type="string", format="password")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Password changed successfully")
 * )
 */
public function changePassword(Request $request)
{
    $request->validate([
        'current_password' => 'required|string',
        'new_password' => 'required|string|min:8|confirmed',
        'new_password_confirmation' => 'required|string',
        'logout_other_sessions' => 'boolean'
    ]);

    $user = $request->user();

    // Verify current password
    if (!Hash::check($request->current_password, $user->password)) {
        return response()->json([
            'success' => false,
            'message' => 'Current password is incorrect',
            'errors' => [
                'current_password' => ['The current password is incorrect.']
            ]
        ], 422);
    }

    // Check if new password is different from current
    if (Hash::check($request->new_password, $user->password)) {
        return response()->json([
            'success' => false,
            'message' => 'New password must be different from current password',
            'errors' => [
                'new_password' => ['New password must be different from current password.']
            ]
        ], 422);
    }

    DB::beginTransaction();
    try {
        // Update password
        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        // Log password change activity
        Log::info('Password changed', [
            'user_id' => $user->id,
            'user_type' => $user instanceof Business ? 'business' : 'admin',
            'user_name' => $user->name,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Optionally revoke other sessions for security
        if ($request->input('logout_other_sessions', false)) {
            // Get current token
            $currentToken = $user->currentAccessToken();

            // Delete all other tokens except current
            $user->tokens()->where('id', '!=', $currentToken->id)->delete();
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully',
            'data' => [
                'user_type' => $user instanceof Business ? 'business' : 'admin',
                'other_sessions_revoked' => $request->input('logout_other_sessions', false),
                'security_notice' => 'Password change has been logged for security purposes'
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollback();
        return response()->json([
            'success' => false,
            'message' => 'Failed to change password',
            'error' => $e->getMessage()
        ], 500);
    }
}

// =================================================================
// Add to App\Http\Controllers\Api\BusinessController.php
// =================================================================

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

// =================================================================
// Add to App\Http\Controllers\Api\AdminController.php
// =================================================================

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
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Tag(
 *     name="Products",
 *     description="Product management for businesses"
 * )
 */
class ProductController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/business/products",
     *     summary="Get all products for the authenticated business",
     *     tags={"Products"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         required=false,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Items per page",
     *         required=false,
     *         @OA\Schema(type="integer", example=15)
     *     ),
     *     @OA\Parameter(
     *         name="category",
     *         in="query",
     *         description="Filter by category",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by name, SKU, or barcode",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="is_active",
     *         in="query",
     *         description="Filter by active status",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Parameter(
     *         name="low_stock",
     *         in="query",
     *         description="Show only low stock items",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Products retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Products retrieved successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $business = Auth::user();
        if (!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $query = Product::where('business_id', $business->id);

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        // Category filter
        if ($request->has('category') && $request->category) {
            $query->where('category', $request->category);
        }

        // Active status filter
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        // Low stock filter
        if ($request->has('low_stock') && $request->low_stock) {
            $query->lowStock();
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $products = $query->paginate($perPage);

        // Transform products with additional data
        $products->getCollection()->transform(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => $product->price,
                'cost_price' => $product->cost_price,
                'quantity' => $product->quantity,
                'min_stock_level' => $product->min_stock_level,
                'category' => $product->category,
                'unit' => $product->unit,
                'image_url' => $product->image_url,
                'is_active' => $product->is_active,
                'attributes' => $product->attributes,
                'stock_status' => $product->getStockStatus(),
                'profit_margin' => $product->getProfitMargin(),
                'created_at' => $product->created_at,
                'updated_at' => $product->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Products retrieved successfully',
            'data' => $products
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/business/products/{id}",
     *     summary="Get a single product",
     *     tags={"Products"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Product retrieved successfully"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Product not found"
     *     )
     * )
     */
    public function show($id)
    {
        $business = Auth::user();
        if (!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $product = Product::where('business_id', $business->id)->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Product retrieved successfully',
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => $product->price,
                'cost_price' => $product->cost_price,
                'quantity' => $product->quantity,
                'min_stock_level' => $product->min_stock_level,
                'category' => $product->category,
                'unit' => $product->unit,
                'image_url' => $product->image_url,
                'is_active' => $product->is_active,
                'attributes' => $product->attributes,
                'stock_status' => $product->getStockStatus(),
                'profit_margin' => $product->getProfitMargin(),
                'created_at' => $product->created_at,
                'updated_at' => $product->updated_at,
            ]
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/business/products",
     *     summary="Create a new product",
     *     tags={"Products"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "price"},
     *             @OA\Property(property="name", type="string", example="Product Name"),
     *             @OA\Property(property="description", type="string", example="Product description"),
     *             @OA\Property(property="sku", type="string", example="SKU-001"),
     *             @OA\Property(property="barcode", type="string", example="1234567890123"),
     *             @OA\Property(property="price", type="number", format="float", example=99.99),
     *             @OA\Property(property="cost_price", type="number", format="float", example=50.00),
     *             @OA\Property(property="quantity", type="integer", example=100),
     *             @OA\Property(property="min_stock_level", type="integer", example=10),
     *             @OA\Property(property="category", type="string", example="Electronics"),
     *             @OA\Property(property="unit", type="string", example="piece"),
     *             @OA\Property(property="image_url", type="string", example="https://example.com/image.jpg"),
     *             @OA\Property(property="is_active", type="boolean", example=true),
     *             @OA\Property(property="attributes", type="object", example={"color": "red", "size": "large"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Product created successfully"
     *     )
     * )
     */
    public function store(Request $request)
    {
        $business = Auth::user();
        if (!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sku' => 'nullable|string|max:255|unique:products,sku,NULL,id,business_id,' . $business->id,
            'barcode' => 'nullable|string|max:255|unique:products,barcode,NULL,id,business_id,' . $business->id,
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|integer|min:0',
            'min_stock_level' => 'nullable|integer|min:0',
            'category' => 'nullable|string|max:255',
            'unit' => 'nullable|string|max:50',
            'image_url' => 'nullable|url|max:500',
            'is_active' => 'nullable|boolean',
            'attributes' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $product = Product::create([
            'business_id' => $business->id,
            'name' => $request->name,
            'description' => $request->description,
            'sku' => $request->sku,
            'barcode' => $request->barcode,
            'price' => $request->price,
            'cost_price' => $request->cost_price,
            'quantity' => $request->quantity ?? 0,
            'min_stock_level' => $request->min_stock_level ?? 0,
            'category' => $request->category,
            'unit' => $request->unit,
            'image_url' => $request->image_url,
            'is_active' => $request->is_active ?? true,
            'attributes' => $request->attributes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => $product
        ], 201);
    }

    /**
     * @OA\Put(
     *     path="/api/business/products/{id}",
     *     summary="Update a product",
     *     tags={"Products"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="sku", type="string"),
     *             @OA\Property(property="barcode", type="string"),
     *             @OA\Property(property="price", type="number"),
     *             @OA\Property(property="cost_price", type="number"),
     *             @OA\Property(property="quantity", type="integer"),
     *             @OA\Property(property="min_stock_level", type="integer"),
     *             @OA\Property(property="category", type="string"),
     *             @OA\Property(property="unit", type="string"),
     *             @OA\Property(property="image_url", type="string"),
     *             @OA\Property(property="is_active", type="boolean"),
     *             @OA\Property(property="attributes", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Product updated successfully"
     *     )
     * )
     */
    public function update(Request $request, $id)
    {
        $business = Auth::user();
        if (!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $product = Product::where('business_id', $business->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'sku' => 'nullable|string|max:255|unique:products,sku,' . $id . ',id,business_id,' . $business->id,
            'barcode' => 'nullable|string|max:255|unique:products,barcode,' . $id . ',id,business_id,' . $business->id,
            'price' => 'sometimes|required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|integer|min:0',
            'min_stock_level' => 'nullable|integer|min:0',
            'category' => 'nullable|string|max:255',
            'unit' => 'nullable|string|max:50',
            'image_url' => 'nullable|url|max:500',
            'is_active' => 'nullable|boolean',
            'attributes' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $product->update($request->only([
            'name', 'description', 'sku', 'barcode', 'price', 'cost_price',
            'quantity', 'min_stock_level', 'category', 'unit', 'image_url',
            'is_active', 'attributes'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $product
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/business/products/{id}",
     *     summary="Delete a product",
     *     tags={"Products"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Product deleted successfully"
     *     )
     * )
     */
    public function destroy($id)
    {
        $business = Auth::user();
        if (!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $product = Product::where('business_id', $business->id)->findOrFail($id);
        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully'
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/business/products/bulk",
     *     summary="Bulk create products",
     *     tags={"Products"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"products"},
     *             @OA\Property(
     *                 property="products",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     required={"name", "price"},
     *                     @OA\Property(property="name", type="string", example="Product Name"),
     *                     @OA\Property(property="description", type="string"),
     *                     @OA\Property(property="sku", type="string"),
     *                     @OA\Property(property="barcode", type="string"),
     *                     @OA\Property(property="price", type="number", example=99.99),
     *                     @OA\Property(property="cost_price", type="number"),
     *                     @OA\Property(property="quantity", type="integer"),
     *                     @OA\Property(property="min_stock_level", type="integer"),
     *                     @OA\Property(property="category", type="string"),
     *                     @OA\Property(property="unit", type="string"),
     *                     @OA\Property(property="image_url", type="string"),
     *                     @OA\Property(property="is_active", type="boolean"),
     *                     @OA\Property(property="attributes", type="object")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Products created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="created", type="integer", example=10),
     *                 @OA\Property(property="failed", type="integer", example=0),
     *                 @OA\Property(property="products", type="array", @OA\Items(type="object"))
     *             )
     *         )
     *     )
     * )
     */
    public function bulkCreate(Request $request)
    {
        $business = Auth::user();
        if (!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'products' => 'required|array|min:1|max:100',
            'products.*.name' => 'required|string|max:255',
            'products.*.description' => 'nullable|string',
            'products.*.sku' => 'nullable|string|max:255',
            'products.*.barcode' => 'nullable|string|max:255',
            'products.*.price' => 'required|numeric|min:0',
            'products.*.cost_price' => 'nullable|numeric|min:0',
            'products.*.quantity' => 'nullable|integer|min:0',
            'products.*.min_stock_level' => 'nullable|integer|min:0',
            'products.*.category' => 'nullable|string|max:255',
            'products.*.unit' => 'nullable|string|max:50',
            'products.*.image_url' => 'nullable|url|max:500',
            'products.*.is_active' => 'nullable|boolean',
            'products.*.attributes' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $created = [];
        $failed = [];
        $skipped = [];

        DB::beginTransaction();
        try {
            foreach ($request->products as $index => $productData) {
                // Check for duplicate SKU or barcode within the same business
                $existingSku = null;
                $existingBarcode = null;

                if (!empty($productData['sku'])) {
                    $existingSku = Product::where('business_id', $business->id)
                        ->where('sku', $productData['sku'])
                        ->first();
                }

                if (!empty($productData['barcode'])) {
                    $existingBarcode = Product::where('business_id', $business->id)
                        ->where('barcode', $productData['barcode'])
                        ->first();
                }

                if ($existingSku || $existingBarcode) {
                    $skipped[] = [
                        'index' => $index,
                        'name' => $productData['name'] ?? 'Unknown',
                        'reason' => $existingSku ? 'SKU already exists' : 'Barcode already exists'
                    ];
                    continue;
                }

                try {
                    $product = Product::create([
                        'business_id' => $business->id,
                        'name' => $productData['name'],
                        'description' => $productData['description'] ?? null,
                        'sku' => $productData['sku'] ?? null,
                        'barcode' => $productData['barcode'] ?? null,
                        'price' => $productData['price'],
                        'cost_price' => $productData['cost_price'] ?? null,
                        'quantity' => $productData['quantity'] ?? 0,
                        'min_stock_level' => $productData['min_stock_level'] ?? 0,
                        'category' => $productData['category'] ?? null,
                        'unit' => $productData['unit'] ?? null,
                        'image_url' => $productData['image_url'] ?? null,
                        'is_active' => $productData['is_active'] ?? true,
                        'attributes' => $productData['attributes'] ?? null,
                    ]);

                    $created[] = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                    ];
                } catch (\Exception $e) {
                    $failed[] = [
                        'index' => $index,
                        'name' => $productData['name'] ?? 'Unknown',
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => sprintf(
                    'Bulk creation completed: %d created, %d skipped, %d failed',
                    count($created),
                    count($skipped),
                    count($failed)
                ),
                'data' => [
                    'created_count' => count($created),
                    'skipped_count' => count($skipped),
                    'failed_count' => count($failed),
                    'created' => $created,
                    'skipped' => $skipped,
                    'failed' => $failed,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Bulk creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/business/products/categories",
     *     summary="Get all product categories for the business",
     *     tags={"Products"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Categories retrieved successfully"
     *     )
     * )
     */
    public function getCategories()
    {
        $business = Auth::user();
        if (!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $categories = Product::where('business_id', $business->id)
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->filter()
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Categories retrieved successfully',
            'data' => $categories
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/business/products/stats",
     *     summary="Get product statistics",
     *     tags={"Products"},
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Statistics retrieved successfully"
     *     )
     * )
     */
    public function getStats()
    {
        $business = Auth::user();
        if (!$business || !($business instanceof Business)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Business access required'
            ], 403);
        }

        $products = Product::where('business_id', $business->id);

        $stats = [
            'total_products' => $products->count(),
            'active_products' => $products->where('is_active', true)->count(),
            'inactive_products' => $products->where('is_active', false)->count(),
            'low_stock_products' => $products->lowStock()->count(),
            'out_of_stock_products' => $products->where('quantity', 0)->count(),
            'total_value' => $products->sum(DB::raw('price * quantity')),
            'total_cost_value' => $products->sum(DB::raw('COALESCE(cost_price, 0) * quantity')),
            'categories_count' => $products->whereNotNull('category')->distinct('category')->count(),
        ];

        // Calculate potential profit
        if ($stats['total_cost_value'] > 0) {
            $stats['potential_profit'] = $stats['total_value'] - $stats['total_cost_value'];
            $stats['profit_margin_percentage'] = round(($stats['potential_profit'] / $stats['total_cost_value']) * 100, 2);
        } else {
            $stats['potential_profit'] = null;
            $stats['profit_margin_percentage'] = null;
        }

        return response()->json([
            'success' => true,
            'message' => 'Statistics retrieved successfully',
            'data' => $stats
        ]);
    }
}


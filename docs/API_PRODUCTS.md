# Product Management API Documentation

## Overview
This API allows businesses to manage their products with full CRUD operations and bulk creation capabilities. All endpoints require authentication using Laravel Sanctum.

**Base URL:** `https://stark-savannah-25644-0470c62ea4b7.herokuapp.com/api`

**Authentication:** Bearer Token (Sanctum)

---

## Endpoints

### 1. Get All Products

Retrieve a paginated list of products for the authenticated business.

**Endpoint:** `GET /api/business/products`

**Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Query Parameters:**
- `page` (integer, optional): Page number (default: 1)
- `per_page` (integer, optional): Items per page (default: 15)
- `category` (string, optional): Filter by category
- `search` (string, optional): Search by name, SKU, or barcode
- `is_active` (boolean, optional): Filter by active status
- `low_stock` (boolean, optional): Show only low stock items
- `sort_by` (string, optional): Field to sort by (default: created_at)
- `sort_order` (string, optional): Sort order - asc or desc (default: desc)

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "name": "Product Name",
        "description": "Product description",
        "sku": "SKU-001",
        "barcode": "1234567890123",
        "price": "99.99",
        "cost_price": "50.00",
        "quantity": 100,
        "min_stock_level": 10,
        "category": "Electronics",
        "unit": "piece",
        "image_url": "https://example.com/image.jpg",
        "is_active": true,
        "attributes": {
          "color": "red",
          "size": "large"
        },
        "stock_status": "in_stock",
        "profit_margin": 99.98,
        "created_at": "2025-12-05T15:00:00.000000Z",
        "updated_at": "2025-12-05T15:00:00.000000Z"
      }
    ],
    "first_page_url": "...",
    "from": 1,
    "last_page": 1,
    "last_page_url": "...",
    "links": [...],
    "next_page_url": null,
    "path": "...",
    "per_page": 15,
    "prev_page_url": null,
    "to": 1,
    "total": 1
  }
}
```

**Example Request:**
```bash
curl -X GET "https://stark-savannah-25644-0470c62ea4b7.herokuapp.com/api/business/products?page=1&per_page=15&category=Electronics" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

---

### 2. Get Single Product

Retrieve details of a specific product.

**Endpoint:** `GET /api/business/products/{id}`

**Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Path Parameters:**
- `id` (integer, required): Product ID

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Product retrieved successfully",
  "data": {
    "id": 1,
    "name": "Product Name",
    "description": "Product description",
    "sku": "SKU-001",
    "barcode": "1234567890123",
    "price": "99.99",
    "cost_price": "50.00",
    "quantity": 100,
    "min_stock_level": 10,
    "category": "Electronics",
    "unit": "piece",
    "image_url": "https://example.com/image.jpg",
    "is_active": true,
    "attributes": {
      "color": "red",
      "size": "large"
    },
    "stock_status": "in_stock",
    "profit_margin": 99.98,
    "created_at": "2025-12-05T15:00:00.000000Z",
    "updated_at": "2025-12-05T15:00:00.000000Z"
  }
}
```

**Example Request:**
```bash
curl -X GET "https://stark-savannah-25644-0470c62ea4b7.herokuapp.com/api/business/products/1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

---

### 3. Create Product

Create a new product for the authenticated business.

**Endpoint:** `POST /api/business/products`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

**Request Body:**
```json
{
  "name": "Product Name",
  "description": "Product description",
  "sku": "SKU-001",
  "barcode": "1234567890123",
  "price": 99.99,
  "cost_price": 50.00,
  "quantity": 100,
  "min_stock_level": 10,
  "category": "Electronics",
  "unit": "piece",
  "image_url": "https://example.com/image.jpg",
  "is_active": true,
  "attributes": {
    "color": "red",
    "size": "large"
  }
}
```

**Required Fields:**
- `name` (string): Product name
- `price` (number): Product price

**Optional Fields:**
- `description` (string): Product description
- `sku` (string): Stock Keeping Unit (must be unique per business)
- `barcode` (string): Product barcode (must be unique per business)
- `cost_price` (number): Cost price for profit calculation
- `quantity` (integer): Current stock quantity (default: 0)
- `min_stock_level` (integer): Minimum stock level for alerts (default: 0)
- `category` (string): Product category
- `unit` (string): Unit of measurement (e.g., "piece", "kg", "liter")
- `image_url` (string): URL to product image
- `is_active` (boolean): Whether product is active (default: true)
- `attributes` (object): Additional product attributes as key-value pairs

**Response (201 Created):**
```json
{
  "success": true,
  "message": "Product created successfully",
  "data": {
    "id": 1,
    "business_id": 1,
    "name": "Product Name",
    "description": "Product description",
    "sku": "SKU-001",
    "barcode": "1234567890123",
    "price": "99.99",
    "cost_price": "50.00",
    "quantity": 100,
    "min_stock_level": 10,
    "category": "Electronics",
    "unit": "piece",
    "image_url": "https://example.com/image.jpg",
    "is_active": true,
    "attributes": {
      "color": "red",
      "size": "large"
    },
    "created_at": "2025-12-05T15:00:00.000000Z",
    "updated_at": "2025-12-05T15:00:00.000000Z"
  }
}
```

**Example Request:**
```bash
curl -X POST "https://stark-savannah-25644-0470c62ea4b7.herokuapp.com/api/business/products" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "name": "Laptop",
    "description": "High-performance laptop",
    "sku": "LAP-001",
    "price": 999.99,
    "cost_price": 600.00,
    "quantity": 50,
    "min_stock_level": 5,
    "category": "Electronics",
    "unit": "piece",
    "is_active": true
  }'
```

---

### 4. Update Product

Update an existing product.

**Endpoint:** `PUT /api/business/products/{id}`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

**Path Parameters:**
- `id` (integer, required): Product ID

**Request Body:** (All fields optional, only include fields to update)
```json
{
  "name": "Updated Product Name",
  "description": "Updated description",
  "price": 1099.99,
  "quantity": 75,
  "is_active": false
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Product updated successfully",
  "data": {
    "id": 1,
    "name": "Updated Product Name",
    "description": "Updated description",
    "price": "1099.99",
    "quantity": 75,
    "is_active": false,
    ...
  }
}
```

**Example Request:**
```bash
curl -X PUT "https://stark-savannah-25644-0470c62ea4b7.herokuapp.com/api/business/products/1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "name": "Updated Product Name",
    "price": 1099.99,
    "quantity": 75
  }'
```

---

### 5. Delete Product

Delete a product.

**Endpoint:** `DELETE /api/business/products/{id}`

**Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Path Parameters:**
- `id` (integer, required): Product ID

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Product deleted successfully"
}
```

**Example Request:**
```bash
curl -X DELETE "https://stark-savannah-25644-0470c62ea4b7.herokuapp.com/api/business/products/1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

---

### 6. Bulk Create Products

Create multiple products in a single request (up to 100 products).

**Endpoint:** `POST /api/business/products/bulk`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

**Request Body:**
```json
{
  "products": [
    {
      "name": "Product 1",
      "description": "Description 1",
      "sku": "SKU-001",
      "barcode": "1234567890123",
      "price": 99.99,
      "cost_price": 50.00,
      "quantity": 100,
      "min_stock_level": 10,
      "category": "Electronics",
      "unit": "piece",
      "is_active": true
    },
    {
      "name": "Product 2",
      "price": 199.99,
      "quantity": 50,
      "category": "Electronics"
    },
    {
      "name": "Product 3",
      "sku": "SKU-003",
      "price": 299.99,
      "quantity": 25
    }
  ]
}
```

**Required Fields (per product):**
- `name` (string): Product name
- `price` (number): Product price

**Optional Fields (per product):**
- All other fields from the single create endpoint

**Response (201 Created):**
```json
{
  "success": true,
  "message": "Bulk creation completed: 3 created, 0 skipped, 0 failed",
  "data": {
    "created_count": 3,
    "skipped_count": 0,
    "failed_count": 0,
    "created": [
      {
        "id": 1,
        "name": "Product 1",
        "sku": "SKU-001"
      },
      {
        "id": 2,
        "name": "Product 2",
        "sku": null
      },
      {
        "id": 3,
        "name": "Product 3",
        "sku": "SKU-003"
      }
    ],
    "skipped": [],
    "failed": []
  }
}
```

**Note:** Products with duplicate SKU or barcode within the same business will be skipped.

**Example Request:**
```bash
curl -X POST "https://stark-savannah-25644-0470c62ea4b7.herokuapp.com/api/business/products/bulk" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "products": [
      {
        "name": "Laptop",
        "sku": "LAP-001",
        "price": 999.99,
        "quantity": 50,
        "category": "Electronics"
      },
      {
        "name": "Mouse",
        "sku": "MOU-001",
        "price": 29.99,
        "quantity": 100,
        "category": "Accessories"
      }
    ]
  }'
```

---

### 7. Get Product Categories

Get all unique categories used by the business's products.

**Endpoint:** `GET /api/business/products/categories`

**Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Categories retrieved successfully",
  "data": [
    "Electronics",
    "Accessories",
    "Clothing",
    "Food & Beverages"
  ]
}
```

**Example Request:**
```bash
curl -X GET "https://stark-savannah-25644-0470c62ea4b7.herokuapp.com/api/business/products/categories" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

---

### 8. Get Product Statistics

Get statistics about the business's products.

**Endpoint:** `GET /api/business/products/stats`

**Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Statistics retrieved successfully",
  "data": {
    "total_products": 150,
    "active_products": 145,
    "inactive_products": 5,
    "low_stock_products": 12,
    "out_of_stock_products": 3,
    "total_value": "125000.00",
    "total_cost_value": "75000.00",
    "potential_profit": "50000.00",
    "profit_margin_percentage": 66.67,
    "categories_count": 8
  }
}
```

**Example Request:**
```bash
curl -X GET "https://stark-savannah-25644-0470c62ea4b7.herokuapp.com/api/business/products/stats" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

---

## Stock Status Values

The `stock_status` field in product responses can have the following values:

- `in_stock`: Quantity is above minimum stock level
- `low_stock`: Quantity is at or below minimum stock level but greater than 0
- `out_of_stock`: Quantity is 0

---

## Error Responses

### 401 Unauthorized
```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden
```json
{
  "success": false,
  "message": "Unauthorized - Business access required"
}
```

### 404 Not Found
```json
{
  "success": false,
  "message": "Product not found"
}
```

### 422 Validation Error
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "name": ["The name field is required."],
    "price": ["The price must be a number."]
  }
}
```

### 500 Server Error
```json
{
  "success": false,
  "message": "Server error message"
}
```

---

## Authentication

All endpoints require authentication using Laravel Sanctum. Include the Bearer token in the Authorization header:

```
Authorization: Bearer YOUR_SANCTUM_TOKEN
```

To get a token, use the business login endpoint:
```
POST /api/auth/business/login
```

---

## Rate Limiting

API requests are subject to rate limiting. Check response headers for rate limit information:
- `X-RateLimit-Limit`: Maximum requests allowed
- `X-RateLimit-Remaining`: Remaining requests in current window

---

## Notes

1. **Business Isolation**: Each business can only access and manage their own products. Products are automatically scoped to the authenticated business.

2. **SKU and Barcode Uniqueness**: SKU and barcode must be unique within a business. Duplicates will be rejected or skipped in bulk operations.

3. **Bulk Creation Limits**: Maximum 100 products can be created in a single bulk request.

4. **Stock Management**: The system automatically calculates stock status based on quantity and minimum stock level.

5. **Profit Margin**: Profit margin is calculated as: `((price - cost_price) / cost_price) * 100`. Returns `null` if cost_price is not set.

6. **Pagination**: List endpoints support pagination with customizable page size.

---

## Interactive API Documentation

You can also access the interactive Swagger UI documentation at:
```
https://stark-savannah-25644-0470c62ea4b7.herokuapp.com/api/documentation
```

This provides an interactive interface to test all endpoints directly from your browser.


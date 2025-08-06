# Delete Vendor API

## Overview

This API endpoint allows businesses to delete vendors they have created, with proper validation to ensure data integrity.

## Endpoint

```
DELETE /api/business/vendors/{vendor_id}
```

## Authentication

-   Requires Bearer token authentication
-   Only accessible by business users
-   Business can only delete their own vendors

## Parameters

-   `vendor_id` (path parameter): The ID of the vendor to delete

## Request Headers

```
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

## Response Format

### Success Response (200)

```json
{
    "success": true,
    "message": "Vendor deleted successfully",
    "data": {
        "deleted_vendor": {
            "id": 1,
            "name": "Vendor Name",
            "email": "vendor@example.com",
            "vendor_code": "VND0010001",
            "business_id": 1,
            "business_name": "Business Name",
            "status": "approved",
            "created_at": "2024-01-15T10:30:00.000000Z"
        },
        "deletion_summary": {
            "vendor_id": 1,
            "vendor_name": "Vendor Name",
            "vendor_code": "VND0010001",
            "deleted_at": "2024-01-15 15:30:00",
            "deleted_by": "Business Name"
        }
    }
}
```

### Error Responses

#### 403 - Cannot Delete Vendor with Purchase Orders

```json
{
    "success": false,
    "message": "Cannot delete vendor with existing purchase orders",
    "errors": {
        "purchase_orders_count": 2,
        "vendor_id": 1,
        "vendor_name": "Vendor Name",
        "suggestion": "All purchase orders must be completed or cancelled before deleting this vendor"
    }
}
```

#### 403 - Cannot Delete Vendor with Pending Payments

```json
{
    "success": false,
    "message": "Cannot delete vendor with pending payments",
    "errors": {
        "pending_payments_count": 1,
        "vendor_id": 1,
        "vendor_name": "Vendor Name",
        "suggestion": "All pending payments must be processed before deleting this vendor"
    }
}
```

#### 404 - Vendor Not Found or Access Denied

```json
{
    "success": false,
    "message": "Vendor not found or access denied"
}
```

#### 403 - Unauthorized Business Access

```json
{
    "success": false,
    "message": "Unauthorized - Business access required"
}
```

## Business Rules

### Deletion Restrictions

1. **Purchase Orders**: Vendors with existing purchase orders cannot be deleted
2. **Pending Payments**: Vendors with pending payments cannot be deleted
3. **Ownership**: Businesses can only delete vendors they created
4. **Authentication**: Only authenticated business users can access this endpoint

### Allowed Deletion Scenarios

1. Vendors with no purchase orders
2. Vendors with only completed purchase orders (all payments confirmed)
3. Vendors that are pending, approved, or rejected (status doesn't matter for deletion)

## Security Features

### Audit Logging

-   All vendor deletions are logged with:
    -   Vendor information before deletion
    -   Business that performed the deletion
    -   Timestamp and IP address
    -   User agent information

### Transaction Safety

-   Uses database transactions to ensure data consistency
-   Rollback on any errors during deletion process

## Example Usage

### cURL Example

```bash
curl -X DELETE \
  https://your-api-domain.com/api/business/vendors/1 \
  -H 'Authorization: Bearer your-token-here' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json'
```

### JavaScript/Fetch Example

```javascript
const deleteVendor = async (vendorId) => {
    try {
        const response = await fetch(`/api/business/vendors/${vendorId}`, {
            method: "DELETE",
            headers: {
                Authorization: `Bearer ${token}`,
                "Content-Type": "application/json",
                Accept: "application/json",
            },
        });

        const data = await response.json();

        if (data.success) {
            console.log(
                "Vendor deleted successfully:",
                data.data.deletion_summary
            );
        } else {
            console.error("Failed to delete vendor:", data.message);
        }
    } catch (error) {
        console.error("Error deleting vendor:", error);
    }
};
```

## Testing

Run the test suite to verify functionality:

```bash
php artisan test tests/Feature/BusinessVendorDeleteTest.php
```

## Related Endpoints

-   `GET /api/business/vendors` - List business vendors
-   `POST /api/business/vendors` - Create new vendor
-   `GET /api/admin/vendors` - Admin vendor management
-   `POST /api/admin/vendors/{vendor}/approve` - Approve vendor
-   `POST /api/admin/vendors/{vendor}/reject` - Reject vendor

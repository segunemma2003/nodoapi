# OTP Approval Flow for Purchase Order Payments

## Overview

When a Purchase Order (PO) is approved, the system automatically initiates a Paystack transfer to the vendor's bank account. If OTP (One-Time Password) is enabled on your Paystack account, the transfer will require OTP verification before completion. This document explains how to complete the OTP approval process from your platform.

## Flow Diagram

```
1. Admin approves PO
   ↓
2. System initiates transfer to vendor
   ↓
3. Paystack sends OTP automatically to admin's registered phone/email
   ↓
4. System returns response indicating OTP is required
   ↓
5. Admin enters OTP in frontend
   ↓
6. System finalizes transfer with OTP
   ↓
7. Payment completed to vendor
```

## API Endpoints

### 1. Approve Purchase Order

**Endpoint:** `POST /api/admin/purchase-orders/{po}/approve`

**Description:** Approves a purchase order and initiates transfer to vendor. If OTP is required, the response will indicate this.

**Request Headers:**
```
Authorization: Bearer {admin_token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "notes": "Optional approval notes"
}
```

**Response (OTP Required):**
```json
{
  "success": true,
  "message": "Purchase order approved. Transfer initiated. OTP required to finalize payment.",
  "data": {
    "purchase_order": {
      "id": 1,
      "po_number": "PO-2025-001",
      "status": "approved",
      "net_amount": "50000.00"
    },
    "approval_details": {
      "approved_by": "Admin Name",
      "approved_at": "2025-12-05 16:00:00",
      "notes": "Approved"
    },
    "payment_details": {
      "amount_paid": "50000.00",
      "payment_reference": "PO_VENDOR_PO-2025-001_1234567890",
      "payment_status": "pending_otp",
      "transfer_code": "TRF_vsyqdmlzble3uii",
      "recipient": "Vendor Name",
      "recipient_account": "1234567890",
      "recipient_bank": "Access Bank",
      "otp_required": true,
      "message": "OTP has been sent to your registered phone/email. Please use the finalize endpoint to complete the transfer."
    }
  }
}
```

**Response (No OTP Required):**
```json
{
  "success": true,
  "message": "Purchase order approved successfully and payment sent to vendor",
  "data": {
    "purchase_order": { ... },
    "payment_details": {
      "payment_status": "completed",
      ...
    }
  }
}
```

**Important Notes:**
- When `otp_required: true`, the transfer is initiated but not completed
- OTP is automatically sent to the phone/email registered with your Paystack account
- The `transfer_code` is stored in the purchase order for later finalization
- The PO status remains `approved` but payment is `pending_otp`

---

### 2. Finalize Transfer with OTP

**Endpoint:** `POST /api/admin/purchase-orders/{po}/finalize-transfer`

**Description:** Finalizes the pending transfer by providing the OTP received from Paystack.

**Request Headers:**
```
Authorization: Bearer {admin_token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "otp": "928783"
}
```

**Request Parameters:**
- `otp` (required, string, 6 digits): The OTP code received via SMS/email from Paystack

**Response (Success):**
```json
{
  "success": true,
  "message": "Transfer finalized successfully. Payment sent to vendor.",
  "data": {
    "purchase_order": {
      "id": 1,
      "po_number": "PO-2025-001",
      "status": "approved",
      "net_amount": "50000.00"
    },
    "transfer_details": {
      "transfer_code": "TRF_vsyqdmlzble3uii",
      "status": "success",
      "amount": "50000.00",
      "recipient": "Vendor Name",
      "recipient_account": "1234567890",
      "recipient_bank": "Access Bank"
    },
    "documents": {
      "purchase_order_pdf": "/storage/purchase_orders/po_1.pdf",
      "payment_receipt_pdf": "/storage/payment_receipts/payment_TRF_vsyqdmlzble3uii.pdf"
    }
  }
}
```

**Response (Error - Invalid OTP):**
```json
{
  "success": false,
  "message": "Failed to finalize transfer: Invalid OTP",
  "error": { ... }
}
```

**Response (Error - No Pending Transfer):**
```json
{
  "success": false,
  "message": "No pending transfer found for this purchase order"
}
```

**Response (Error - PO Not Approved):**
```json
{
  "success": false,
  "message": "Purchase order must be approved before finalizing transfer"
}
```

---

## Frontend Implementation Guide

### Step 1: Approve Purchase Order

```javascript
// Approve PO
const approvePO = async (poId, notes = '') => {
  try {
    const response = await fetch(`/api/admin/purchase-orders/${poId}/approve`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${adminToken}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ notes })
    });

    const data = await response.json();

    if (data.success) {
      // Check if OTP is required
      if (data.data.payment_details.otp_required) {
        // Show OTP input modal/form
        showOTPModal(data.data.payment_details.transfer_code, poId);
      } else {
        // Payment completed successfully
        showSuccessMessage('Purchase order approved and payment sent!');
      }
    }
  } catch (error) {
    console.error('Error approving PO:', error);
  }
};
```

### Step 2: Show OTP Input Form

```javascript
// Show OTP input modal
const showOTPModal = (transferCode, poId) => {
  const otp = prompt(
    'OTP has been sent to your registered phone/email.\n\n' +
    'Please enter the 6-digit OTP code to finalize the payment:'
  );

  if (otp && otp.length === 6) {
    finalizeTransfer(poId, otp);
  } else if (otp) {
    alert('Invalid OTP format. Please enter a 6-digit code.');
  }
};
```

### Step 3: Finalize Transfer with OTP

```javascript
// Finalize transfer with OTP
const finalizeTransfer = async (poId, otp) => {
  try {
    const response = await fetch(`/api/admin/purchase-orders/${poId}/finalize-transfer`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${adminToken}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ otp })
    });

    const data = await response.json();

    if (data.success) {
      showSuccessMessage('Payment finalized successfully! Transfer completed to vendor.');
      // Refresh PO details or redirect
      refreshPODetails(poId);
    } else {
      showErrorMessage(data.message || 'Failed to finalize transfer. Please check the OTP and try again.');
    }
  } catch (error) {
    console.error('Error finalizing transfer:', error);
    showErrorMessage('An error occurred while finalizing the transfer.');
  }
};
```

### Complete Example with React

```jsx
import React, { useState } from 'react';

const ApprovePOButton = ({ poId, onApproved }) => {
  const [loading, setLoading] = useState(false);
  const [showOTPModal, setShowOTPModal] = useState(false);
  const [otp, setOtp] = useState('');
  const [transferCode, setTransferCode] = useState(null);

  const handleApprove = async () => {
    setLoading(true);
    try {
      const response = await fetch(`/api/admin/purchase-orders/${poId}/approve`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ notes: '' })
      });

      const data = await response.json();

      if (data.success) {
        if (data.data.payment_details.otp_required) {
          setTransferCode(data.data.payment_details.transfer_code);
          setShowOTPModal(true);
        } else {
          alert('PO approved and payment sent successfully!');
          onApproved();
        }
      }
    } catch (error) {
      alert('Error approving PO: ' + error.message);
    } finally {
      setLoading(false);
    }
  };

  const handleFinalize = async () => {
    if (otp.length !== 6) {
      alert('Please enter a valid 6-digit OTP');
      return;
    }

    setLoading(true);
    try {
      const response = await fetch(`/api/admin/purchase-orders/${poId}/finalize-transfer`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ otp })
      });

      const data = await response.json();

      if (data.success) {
        alert('Payment finalized successfully!');
        setShowOTPModal(false);
        setOtp('');
        onApproved();
      } else {
        alert('Error: ' + data.message);
      }
    } catch (error) {
      alert('Error finalizing transfer: ' + error.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      <button onClick={handleApprove} disabled={loading}>
        {loading ? 'Processing...' : 'Approve PO'}
      </button>

      {showOTPModal && (
        <div className="otp-modal">
          <h3>Enter OTP</h3>
          <p>OTP has been sent to your registered phone/email.</p>
          <input
            type="text"
            maxLength="6"
            value={otp}
            onChange={(e) => setOtp(e.target.value.replace(/\D/g, ''))}
            placeholder="Enter 6-digit OTP"
          />
          <div>
            <button onClick={handleFinalize} disabled={loading || otp.length !== 6}>
              {loading ? 'Finalizing...' : 'Finalize Transfer'}
            </button>
            <button onClick={() => setShowOTPModal(false)}>Cancel</button>
          </div>
        </div>
      )}
    </>
  );
};

export default ApprovePOButton;
```

---

## cURL Examples

### Approve Purchase Order

```bash
curl -X POST https://your-domain.com/api/admin/purchase-orders/1/approve \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "notes": "Approved for processing"
  }'
```

### Finalize Transfer with OTP

```bash
curl -X POST https://your-domain.com/api/admin/purchase-orders/1/finalize-transfer \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "otp": "928783"
  }'
```

---

## Error Handling

### Common Errors

1. **Invalid OTP**
   - **Error:** `"Failed to finalize transfer: Invalid OTP"`
   - **Solution:** Verify the OTP code matches the one received. OTP codes expire after a few minutes.

2. **No Pending Transfer**
   - **Error:** `"No pending transfer found for this purchase order"`
   - **Solution:** The transfer may have already been finalized or the PO was not approved with OTP requirement.

3. **PO Not Approved**
   - **Error:** `"Purchase order must be approved before finalizing transfer"`
   - **Solution:** Approve the purchase order first using the approve endpoint.

4. **OTP Expired**
   - **Error:** `"Failed to finalize transfer: OTP expired"`
   - **Solution:** Approve the PO again to receive a new OTP.

---

## Important Notes

1. **OTP Delivery:** OTP is automatically sent to the phone number and email address registered with your Paystack account when the transfer is initiated.

2. **OTP Expiry:** OTP codes typically expire after 5-10 minutes. If expired, you may need to re-initiate the transfer.

3. **Transfer Status:** After finalization, you can verify the transfer status using the `transfer_code` returned in the response.

4. **Security:** Never share OTP codes. They are single-use and time-sensitive.

5. **Retry Logic:** If OTP finalization fails, you can retry with the same OTP (if not expired) or approve the PO again to get a new OTP.

---

## Testing

### Test Flow

1. Create a test purchase order
2. Approve the PO using the approve endpoint
3. Check the response for `otp_required: true`
4. Check your registered phone/email for the OTP
5. Use the finalize endpoint with the OTP
6. Verify the transfer is completed

### Test with Invalid OTP

```bash
# Try with wrong OTP
curl -X POST https://your-domain.com/api/admin/purchase-orders/1/finalize-transfer \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"otp": "000000"}'
```

Expected: Error response indicating invalid OTP

---

## Support

For issues or questions:
- Check Paystack dashboard for transfer status
- Verify OTP settings in Paystack account
- Ensure phone/email is correctly registered in Paystack
- Contact support if OTP is not being received

---

**Last Updated:** December 5, 2025

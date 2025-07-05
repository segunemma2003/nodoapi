@component('mail::message')
# Purchase Order Approved ✅

Hello {{ $business->name }},

Great news! Your purchase order has been approved by our admin team.

## Purchase Order Details

**PO Number:** {{ $purchaseOrder->po_number }}
**Vendor:** {{ $vendor->name }}
**Approved By:** {{ $approvedBy->name }}
**Approval Date:** {{ $purchaseOrder->approved_at->format('F j, Y g:i A') }}

### Order Summary
@if(count($items) > 0)
@component('mail::table')
| Item | Quantity | Unit Price | Total |
|:-----|:---------|:-----------|:------|
@foreach($items as $item)
| {{ $item['name'] }} | {{ $item['quantity'] }} | ₦{{ number_format($item['unit_price'], 2) }} | ₦{{ number_format($item['line_total'], 2) }} |
@endforeach
@endcomponent
@endif

**Total Amount:** ₦{{ number_format($purchaseOrder->net_amount, 2) }}

## Payment Information

@component('mail::panel')
**Payment Status:** ✅ Payment Completed
**Amount:** ₦{{ number_format($paymentDetails['amount'], 2) }}
**Payment Reference:** {{ $paymentDetails['reference'] }}
**Vendor Paid:** {{ $vendor->name }}
**Payment Date:** {{ now()->format('F j, Y g:i A') }}

Payment has been sent directly to the vendor and should reflect in their account within 1-2 business days.
@endcomponent

## Next Steps

1. **Coordinate with Vendor:** Contact {{ $vendor->name }} to arrange delivery
2. **Track Delivery:** Monitor your order progress
3. **Confirm Receipt:** Update order status upon delivery
4. **Make Repayment:** Remember to repay this amount to restore your credit line

@component('mail::button', ['url' => $dashboardUrl])
View Purchase Order Details
@endcomponent

**Important:** This purchase order amount (₦{{ number_format($purchaseOrder->net_amount, 2) }}) has been deducted from your available credit. To restore your spending power, please submit payment evidence when ready.

If you have any questions about this purchase order, please contact our support team.

Best regards,<br>
{{ config('app.name') }}
@endcomponent

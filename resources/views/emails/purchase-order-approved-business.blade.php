@component('mail::message')
# Purchase Order Approved & Payment Sent

Hello {{ $business->name }},

Great news! Your purchase order has been approved and payment has been sent to your account.

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
| {{ $item['name'] }} | {{ $item['quantity'] }} | ₦{{ number_format($item['unit_price'], 2) }} | ₦{{ number_format($item['total_price'], 2) }} |
@endforeach
@endcomponent
@endif

**Total Amount:** ₦{{ number_format($purchaseOrder->net_amount, 2) }}

## Payment Information

@component('mail::panel')
**Payment Status:** Completed ✅
**Amount Sent:** ₦{{ number_format($paymentDetails['amount'], 2) }}
**Payment Reference:** {{ $paymentDetails['reference'] }}
**Transfer Date:** {{ now()->format('F j, Y g:i A') }}

The payment has been sent to your registered bank account and should reflect within 1-2 business days.
@endcomponent

## Next Steps

1. **Contact Vendor:** Coordinate delivery with {{ $vendor->name }}
2. **Track Delivery:** Monitor your order progress
3. **Confirm Receipt:** Update order status upon delivery

@component('mail::button', ['url' => $dashboardUrl])
View Purchase Order Details
@endcomponent

If you have any questions about this purchase order or payment, please contact our support team.

Best regards,<br>
{{ config('app.name') }}
@endcomponent

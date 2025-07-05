@component('mail::message')
# Purchase Order Rejected

Hello {{ $vendor->name }},

We regret to inform you that a purchase order has been rejected.

## Purchase Order Details

**PO Number:** {{ $purchaseOrder->po_number }}
**Business:** {{ $business->name }}
**Rejection Date:** {{ $purchaseOrder->approved_at->format('F j, Y g:i A') }}
**Rejected By:** {{ $rejectedBy->name }}

### Order Details
@if(count($items) > 0)
@component('mail::table')
| Item | Description | Quantity | Unit Price | Total |
|:-----|:------------|:---------|:-----------|:------|
@foreach($items as $item)
| {{ $item['name'] }} | {{ $item['description'] ?? 'N/A' }} | {{ $item['quantity'] }} | ₦{{ number_format($item['unit_price'], 2) }} | ₦{{ number_format($item['total_price'], 2) }} |
@endforeach
@endcomponent
@endif

**Order Value:** ₦{{ number_format($purchaseOrder->net_amount, 2) }}

## Rejection Reason

@component('mail::panel')
{{ $rejectionReason }}
@endcomponent

## What This Means

- This purchase order will not be fulfilled
- No payment will be processed
- No delivery is required from your side

If you have any questions about this rejection or would like to discuss potential future orders, please contact our support team.

## Need Help?

If you believe this rejection was made in error or need clarification, please contact us:

**Email:** {{ $supportEmail }}

Thank you for your understanding,<br>
{{ config('app.name') }}
@endcomponent

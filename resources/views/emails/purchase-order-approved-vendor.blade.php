@component('mail::message')
# Purchase Order Approved - Ready for Fulfillment

Hello {{ $vendor->name }},

A purchase order has been approved and is ready for fulfillment.

## Purchase Order Details

**PO Number:** {{ $purchaseOrder->po_number }}
**Business:** {{ $business->name }}
**Approval Date:** {{ $purchaseOrder->approved_at->format('F j, Y g:i A') }}
**Expected Delivery:** {{ $purchaseOrder->expected_delivery_date ? $purchaseOrder->expected_delivery_date->format('F j, Y') : 'TBD' }}

### Items to Deliver
@if(count($items) > 0)
@component('mail::table')
| Item | Description | Quantity | Unit Price | Total |
|:-----|:------------|:---------|:-----------|:------|
@foreach($items as $item)
| {{ $item['name'] }} | {{ $item['description'] ?? 'N/A' }} | {{ $item['quantity'] }} | ₦{{ number_format($item['unit_price'], 2) }} | ₦{{ number_format($item['total_price'], 2) }} |
@endforeach
@endcomponent
@endif

**Total Order Value:** ₦{{ number_format($purchaseOrder->net_amount, 2) }}

## Business Contact Information

**Business:** {{ $business->name }}
**Email:** {{ $business->email }}
**Phone:** {{ $business->phone ?? 'Contact via email' }}
@if($business->address)
**Address:** {{ $business->address }}
@endif

@component('mail::panel')
**Payment Status:** ✅ Payment has been processed to the business
**Your Action Required:** Please fulfill this order and coordinate delivery
@endcomponent

## Special Instructions

@if($purchaseOrder->notes)
{{ $purchaseOrder->notes }}
@else
No special instructions provided.
@endif

Please coordinate directly with {{ $business->name }} for delivery arrangements and confirmation.

Thanks for your service,<br>
{{ config('app.name') }}
@endcomponent

@component('mail::message')
# New Purchase Order Pending Approval ⏳

Hello Admin,

A new purchase order has been submitted and requires your approval for payment processing.

## Purchase Order Details

**PO Number:** {{ $purchaseOrder->po_number }}
**Business:** {{ $business->name }}
**Vendor:** {{ $vendor->name }}
**Order Date:** {{ $purchaseOrder->order_date->format('F j, Y') }}
**Total Amount:** ₦{{ number_format($purchaseOrder->net_amount, 2) }}

## Order Summary

**Description:** {{ $purchaseOrder->description }}

### Items Ordered
@if(count($items) > 0)
@component('mail::table')
| Item | Description | Quantity | Unit Price | Total |
|:-----|:------------|:---------|:-----------|:------|
@foreach($items as $item)
| {{ $item['name'] }} | {{ $item['description'] ?? 'N/A' }} | {{ $item['quantity'] }} | ₦{{ number_format($item['unit_price'], 2) }} | ₦{{ number_format($item['line_total'], 2) }} |
@endforeach
@endcomponent
@endif

**Subtotal:** ₦{{ number_format($purchaseOrder->total_amount, 2) }}
@if($purchaseOrder->tax_amount > 0)
**Tax:** ₦{{ number_format($purchaseOrder->tax_amount, 2) }}
@endif
@if($purchaseOrder->discount_amount > 0)
**Discount:** -₦{{ number_format($purchaseOrder->discount_amount, 2) }}
@endif
**Net Amount:** ₦{{ number_format($purchaseOrder->net_amount, 2) }}

## Business Information

**Email:** {{ $business->email }}
**Phone:** {{ $business->phone ?? 'N/A' }}
**Business Type:** {{ $business->business_type }}
**Available Credit:** ₦{{ number_format($business->available_balance, 2) }}
**Credit Utilization:** {{ $business->getCreditUtilization() }}%

## Vendor Payment Details

**Vendor:** {{ $vendor->name }}
**Account Number:** {{ $vendor->account_number ?? 'Not provided' }}
**Bank:** {{ $vendor->bank_name ?? 'Not provided' }}

@if(!$vendor->account_number || !$vendor->bank_code)
@component('mail::panel')
⚠️ **WARNING:** Vendor bank details are incomplete. Please update vendor information before approving.
@endcomponent
@endif

@component('mail::button', ['url' => $adminUrl])
Review & Approve Purchase Order
@endcomponent

**Important:** Upon approval, payment will be automatically sent to the vendor's account. Please verify vendor bank details are correct before approving.

Thanks,<br>
{{ config('app.name') }}
@endcomponent

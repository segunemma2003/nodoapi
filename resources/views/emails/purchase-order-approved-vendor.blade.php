@component('mail::message')
# Purchase Order Approved - Payment Sent! 💰

Hello {{ $vendor->name }},

Excellent news! A purchase order has been approved and **payment has been sent to your account**.

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
| {{ $item['name'] }} | {{ $item['description'] ?? 'N/A' }} | {{ $item['quantity'] }} | ₦{{ number_format($item['unit_price'], 2) }} | ₦{{ number_format($item['line_total'], 2) }} |
@endforeach
@endcomponent
@endif

**Total Order Value:** ₦{{ number_format($purchaseOrder->net_amount, 2) }}

## 💸 Payment Details

@component('mail::panel')
**✅ PAYMENT SENT TO YOUR ACCOUNT**

**Amount Paid:** ₦{{ number_format($paymentDetails['amount'], 2) }}
**Payment Reference:** {{ $paymentDetails['reference'] }}
**Your Account:** {{ $vendor->account_number }}
**Bank:** {{ $vendor->bank_name }}
**Payment Date:** {{ now()->format('F j, Y g:i A') }}

The payment should reflect in your account within 1-2 business days.
@endcomponent

## Business Contact Information

**Business:** {{ $business->name }}
**Email:** {{ $business->email }}
**Phone:** {{ $business->phone ?? 'Contact via email' }}
@if($business->address)
**Address:** {{ $business->address }}
@endif

## Special Instructions

@if($purchaseOrder->notes)
{{ $purchaseOrder->notes }}
@else
No special instructions provided.
@endif

## Your Action Required

Since payment has been processed, please:
1. **Prepare the order** for delivery/pickup
2. **Contact the business** to coordinate delivery
3. **Deliver the items** as specified
4. **Confirm delivery** with the business

Please coordinate directly with {{ $business->name }} for delivery arrangements.

**Note:** You have received full payment upfront. Please ensure timely delivery of all items as specified in this purchase order.

Thanks for your service,<br>
{{ config('app.name') }}
@endcomponent

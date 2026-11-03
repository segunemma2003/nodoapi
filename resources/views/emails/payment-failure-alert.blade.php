@component('mail::message')
# {{ $title }}

A payment to a vendor did not go through. The vendor has **not** been paid for this purchase order and it needs attention.

**PO Number:** {{ $purchaseOrder->po_number }}
**Amount:** ₦{{ number_format($purchaseOrder->net_amount, 2) }}
**Business:** {{ $business?->name ?? '—' }}
**Vendor:** {{ $vendor?->name ?? '—' }}
**Vendor bank:** {{ $vendor?->bank_name ?? '—' }} · {{ $vendor?->account_number ?? '—' }} ({{ $vendor?->account_holder_name ?? '—' }})
@if($reference)
**Paystack reference:** {{ $reference }}
@endif
**Time:** {{ now()->format('F j, Y g:i A') }}

@component('mail::panel')
**Reason:** {{ $reason }}
@endcomponent

Check the vendor's bank details and the Paystack dashboard, then retry the payment from the admin portal.
@endcomponent

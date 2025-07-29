@component('mail::message')
# New Vendor Pending Approval ⏳

Hello Admin,

A new vendor has been submitted and requires your approval.

## Vendor Details

**Vendor Name:** {{ $vendor->name }}
**Email:** {{ $vendor->email }}
**Phone:** {{ $vendor->phone ?? 'Not provided' }}
**Category:** {{ $vendor->category ?? 'Not specified' }}
**Vendor Code:** {{ $vendor->vendor_code }}

## Business Information

**Business:** {{ $business->name }}
**Email:** {{ $business->email }}
**Business Type:** {{ $business->business_type }}

## Bank Details

**Bank:** {{ $vendor->bank_name }}
**Account Number:** {{ $vendor->account_number }}
**Account Holder:** {{ $vendor->account_holder_name ?? 'Not verified' }}

@if($vendor->address)
**Address:** {{ $vendor->address }}
@endif

@if($vendor->payment_terms)
**Payment Terms:** {{ json_encode($vendor->payment_terms) }}
@endif

@component('mail::button', ['url' => $adminUrl])
Review & Approve Vendor
@endcomponent

**Important:** Upon approval, this vendor will be able to receive purchase orders and payments from the business.

Thanks,<br>
{{ config('app.name') }}
@endcomponent

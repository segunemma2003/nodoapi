@component('mail::message')
# Vendor Approved ✅

Hello {{ $business->name }},

Great news! Your vendor has been approved by our admin team.

## Vendor Details

**Vendor Name:** {{ $vendor->name }}
**Email:** {{ $vendor->email }}
**Category:** {{ $vendor->category ?? 'Not specified' }}
**Vendor Code:** {{ $vendor->vendor_code }}

## Bank Details

**Bank:** {{ $vendor->bank_name }}
**Account Number:** {{ $vendor->account_number }}
**Account Holder:** {{ $vendor->account_holder_name ?? 'Not verified' }}

## Approval Information

**Approved By:** {{ $approvalDetails['approved_by'] ?? 'Admin' }}
**Approval Date:** {{ $approvalDetails['approved_at'] ?? now()->format('F j, Y g:i A') }}

@if(isset($approvalDetails['notes']) && $approvalDetails['notes'])
**Admin Notes:** {{ $approvalDetails['notes'] }}
@endif

## What This Means

✅ **You can now create purchase orders** with this vendor
✅ **Payments will be processed** automatically when POs are approved
✅ **Vendor is ready** to receive orders and payments

## Next Steps

1. **Create Purchase Orders** with this vendor
2. **Monitor Orders** through your dashboard
3. **Track Payments** and delivery status

@component('mail::button', ['url' => $dashboardUrl])
View Vendor Details
@endcomponent

If you have any questions about this vendor approval, please contact our support team.

Best regards,<br>
{{ config('app.name') }}
@endcomponent

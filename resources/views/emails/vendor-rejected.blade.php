@component('mail::message')
# Vendor Rejected ❌

Hello {{ $business->name }},

We regret to inform you that your vendor has been rejected by our admin team.

## Vendor Details

**Vendor Name:** {{ $vendor->name }}
**Email:** {{ $vendor->email }}
**Category:** {{ $vendor->category ?? 'Not specified' }}
**Vendor Code:** {{ $vendor->vendor_code }}

## Rejection Information

**Rejected By:** {{ $rejectionDetails['rejected_by'] ?? 'Admin' }}
**Rejection Date:** {{ $rejectionDetails['rejected_at'] ?? now()->format('F j, Y g:i A') }}

## Rejection Reason

@component('mail::panel')
{{ $rejectionDetails['rejection_reason'] ?? 'No reason provided' }}
@endcomponent

## What This Means

❌ **You cannot create purchase orders** with this vendor
❌ **No payments will be processed** for this vendor
❌ **Vendor is not available** for transactions

## Next Steps

1. **Review the rejection reason** carefully
2. **Update vendor information** if needed
3. **Contact support** if you believe this was an error
4. **Create a new vendor** with corrected information

## Need Help?

If you believe this rejection was made in error or need clarification, please contact our support team:

**Email:** {{ config('mail.from.address') }}

You can also update the vendor information and resubmit for approval.

@component('mail::button', ['url' => $dashboardUrl])
View Vendor Details
@endcomponent

Thank you for your understanding,<br>
{{ config('app.name') }}
@endcomponent

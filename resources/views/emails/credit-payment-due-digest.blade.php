@component('mail::message')
# Vendor payments due

@if($overduePurchaseOrders->count() > 0)
## ⚠️ Overdue ({{ $overduePurchaseOrders->count() }})

@component('mail::table')
| PO Number | Business | Vendor | Due Date | Days Overdue | Outstanding |
|:----------|:---------|:-------|:---------|:--------------|:-------------|
@foreach($overduePurchaseOrders as $po)
| {{ $po->po_number }} | {{ $po->business?->name }} | {{ $po->vendor?->name }} | {{ $po->due_date?->format('M j, Y') }} | {{ $po->getDaysOverdue() }} | ₦{{ number_format($po->outstanding_amount, 2) }} |
@endforeach
@endcomponent
@endif

@if($dueSoonPurchaseOrders->count() > 0)
## Due within 3 days ({{ $dueSoonPurchaseOrders->count() }})

@component('mail::table')
| PO Number | Business | Vendor | Due Date | Outstanding |
|:----------|:---------|:-------|:---------|:-------------|
@foreach($dueSoonPurchaseOrders as $po)
| {{ $po->po_number }} | {{ $po->business?->name }} | {{ $po->vendor?->name }} | {{ $po->due_date?->format('M j, Y') }} | ₦{{ number_format($po->outstanding_amount, 2) }} |
@endforeach
@endcomponent
@endif

@component('mail::button', ['url' => $adminUrl])
Open Credit Plan
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent

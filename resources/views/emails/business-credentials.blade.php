/emails/business-credentials.blade.php
@component('mail::message')
# Welcome to NodoPay Platform

Hello {{ $business->name }},

Your business account has been created successfully. Please find your login credentials below:

**Email:** {{ $business->email }}
**Password:** {{ $password }}

@component('mail::button', ['url' => config('app.frontend_url') . '/login'])
Login to Your Account
@endcomponent

For security reasons, please change your password after your first login.

Thanks,<br>
{{ config('app.name') }}
@endcomponent

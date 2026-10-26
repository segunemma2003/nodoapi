<?php

namespace App\Support;

use App\Models\Business;
use App\Models\BusinessStaff;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the Business a request is acting on, whether the authenticated
 * principal is the Business's own login or one of its BusinessStaff logins.
 * Every business-scoped controller should read the acting business through
 * here instead of `Auth::user()` directly, so staff logins see the same
 * dashboard (vendors, products, purchase orders, credit plan, wallet) as
 * the business's own account.
 */
class BusinessContext
{
    public static function current(): ?Business
    {
        $user = Auth::user();

        if ($user instanceof BusinessStaff) {
            return $user->business;
        }

        return $user instanceof Business ? $user : null;
    }
}

<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    /**
     * Billing and delinquents lists.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('billing.view');
    }

    /**
     * A single invoice with its amounts.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.view');
    }

    /**
     * Register a payment for the invoice.
     */
    public function pay(User $user, Invoice $invoice): bool
    {
        return $user->can('billing.pay');
    }
}

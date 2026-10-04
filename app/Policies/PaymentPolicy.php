<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /**
     * Print the payment receipt.
     */
    public function viewReceipt(User $user, Payment $payment): bool
    {
        return $user->can('billing.view');
    }

    /**
     * Refund the payment; a payment is refunded at most once.
     */
    public function refund(User $user, Payment $payment): bool
    {
        return $user->can('billing.refund') && ! $payment->isRefunded();
    }
}

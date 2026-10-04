<?php

namespace App\Policies;

use App\Models\User;

class CardPolicy
{
    /**
     * Quick search of the card status.
     */
    public function check(User $user): bool
    {
        return $user->can('cards.check');
    }

    /**
     * Change plan, replacement card, suspend, reactivate and cancel.
     */
    public function manage(User $user): bool
    {
        return $user->can('cards.manage');
    }

    /**
     * Card stock screen.
     */
    public function viewStock(User $user): bool
    {
        return $user->can('cards.stock.view');
    }

    /**
     * Register a batch and discard a card.
     */
    public function manageStock(User $user): bool
    {
        return $user->can('cards.stock.manage');
    }
}

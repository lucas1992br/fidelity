<?php

namespace App\Actions\Plans;

use App\Models\Plan;
use Illuminate\Validation\ValidationException;

/**
 * Soft delete a plan nobody ever subscribed to; plans with customers must be
 * deactivated instead so their history stays intact.
 */
class DeletePlan
{
    /**
     * @throws ValidationException
     */
    public function handle(Plan $plan): void
    {
        if ($plan->customers()->withTrashed()->exists()) {
            throw ValidationException::withMessages([
                'plan' => 'Plano com titulares não pode ser excluído; desative-o.',
            ]);
        }

        $plan->delete();
    }
}

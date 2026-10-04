<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Move the holder to another active plan (US-7.2), in one transaction: the
 * customer's plan and the `plan_changes` row with the plans and prices
 * from/to, the optional reason and the user. Blocked while the active
 * dependents exceed the new plan's limit. Invoices already issued keep their
 * plan and amount; the new price applies from the next generated invoice.
 * Requires `cards.manage`.
 */
class ChangePlan
{
    /**
     * @param  array{plan_id?: int|string|null, reason?: string|null}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Customer $customer, array $input): Customer
    {
        if (! $user->can('cards.manage')) {
            throw new AuthorizationException;
        }

        $validated = Validator::make([
            'plan_id' => $input['plan_id'] ?? null,
            'reason' => filled($input['reason'] ?? null) ? trim((string) $input['reason']) : null,
        ], [
            'plan_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [], [
            'plan_id' => 'plano',
            'reason' => 'motivo',
        ])->validate();

        return DB::transaction(function () use ($user, $customer, $validated): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $plan = Plan::query()->active()->whereKey($validated['plan_id'])->first();

            if ($plan === null) {
                throw ValidationException::withMessages(['plan_id' => 'Selecione um plano ativo.']);
            }

            if ($plan->id === $customer->plan_id) {
                throw ValidationException::withMessages(['plan_id' => "O titular já está no plano {$plan->name}."]);
            }

            if ($message = self::dependentsBlockMessage($plan, $customer->activeDependents()->count())) {
                throw ValidationException::withMessages(['plan_id' => $message]);
            }

            $fromPlan = $customer->plan;

            $customer->update(['plan_id' => $plan->id]);

            $customer->planChanges()->create([
                'from_plan_id' => $fromPlan->id,
                'to_plan_id' => $plan->id,
                'from_price' => $fromPlan->price,
                'to_price' => $plan->price,
                'reason' => $validated['reason'],
                'user_id' => $user->id,
            ]);

            return $customer->setRelation('plan', $plan);
        });
    }

    /**
     * Message blocking the change while the active dependents exceed the new
     * plan's limit, or null when they fit.
     */
    public static function dependentsBlockMessage(Plan $plan, int $activeDependents): ?string
    {
        $excess = $activeDependents - $plan->max_dependents;

        if ($excess <= 0) {
            return null;
        }

        return "Inative {$excess} dependente(s) para trocar para o plano {$plan->name}.";
    }
}

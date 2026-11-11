<?php

namespace App\Actions\Customers;

use App\Actions\Plans\SavePlan;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\User;
use App\Support\Customers\CustomerRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Change the holder's plan and/or monthly amount (US-7.2), in one
 * transaction: the customer's plan and amount and the `plan_changes` row with
 * the plans and amounts from/to, the optional reason and the user. The amount
 * is typed (the new plan's price is the default when none is given), so
 * keeping the plan with a new amount is a valid change. Blocked while the active dependents exceed
 * the new plan's limit. Invoices already issued keep their plan and amount;
 * the new amount applies from the next generated invoice. Requires `cards.manage`.
 */
class ChangePlan
{
    /**
     * @param  array{plan_id?: int|string|null, monthly_amount?: string|int|float|null, reason?: string|null}  $input
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
            'monthly_amount' => SavePlan::normalizePrice($input['monthly_amount'] ?? null),
            'reason' => filled($input['reason'] ?? null) ? trim((string) $input['reason']) : null,
        ], [
            'plan_id' => ['required', 'integer'],
            'monthly_amount' => ['nullable', CustomerRules::MONEY_REGEX],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'monthly_amount.regex' => CustomerRules::messages()['monthly_amount.regex'],
        ], [
            'plan_id' => 'plano',
            'monthly_amount' => 'valor mensal',
            'reason' => 'motivo',
        ])->validate();

        return DB::transaction(function () use ($user, $customer, $validated): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $isSamePlan = (int) $validated['plan_id'] === $customer->plan_id;
            $plan = $isSamePlan
                ? $customer->plan
                : Plan::query()->active()->whereKey($validated['plan_id'])->first();

            if ($plan === null) {
                throw ValidationException::withMessages(['plan_id' => 'Selecione um plano ativo.']);
            }

            $validated['monthly_amount'] ??= $isSamePlan ? $customer->monthly_amount : $plan->price;

            if ($customer->isEmployee()) {
                $validated['monthly_amount'] = '0.00';
            }

            if ($isSamePlan && bccomp($validated['monthly_amount'], $customer->monthly_amount, 2) === 0) {
                throw ValidationException::withMessages(['plan_id' => "O titular já está no plano {$plan->name} com este valor."]);
            }

            if ($message = self::dependentsBlockMessage($plan, $customer->activeDependents()->count())) {
                throw ValidationException::withMessages(['plan_id' => $message]);
            }

            $fromPlan = $customer->plan;
            $fromAmount = $customer->monthly_amount;

            $customer->update(['plan_id' => $plan->id, 'monthly_amount' => $validated['monthly_amount']]);

            $customer->planChanges()->create([
                'from_plan_id' => $fromPlan->id,
                'to_plan_id' => $plan->id,
                'from_price' => $fromAmount,
                'to_price' => $validated['monthly_amount'],
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

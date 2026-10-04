<?php

namespace App\Actions\Cards;

use App\Actions\Billing\IssueInvoice;
use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\User;
use App\Support\Billing\DueDate;
use App\Support\Customers\CustomerRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Bring back a holder whose last card was cancelled (reingresso), in one
 * transaction: new plan and due day, a stock card linked as `active`, the
 * "Reingresso" `plan_changes` row and a new full-price invoice on the next
 * `due_day` (moved to the following month when that month already has an
 * invoice). Dependents keep their sequence; kept invoices stay chargeable.
 */
class ReenrollCustomer
{
    public const string Reason = 'Reingresso';

    public function __construct(
        protected AssignCard $assignCard,
        protected IssueInvoice $issueInvoice,
    ) {}

    /**
     * @param  array{card_number?: string|null, plan_id?: int|string|null, due_day?: int|string|null}  $input
     *
     * @throws ValidationException
     */
    public function handle(Customer $customer, array $input, User $user): Customer
    {
        $input['card_number'] = trim((string) ($input['card_number'] ?? ''));

        $validated = Validator::make($input, [
            'card_number' => ['required', 'string', 'max:255'],
            ...Arr::only(CustomerRules::rules(), ['plan_id', 'due_day']),
        ], CustomerRules::messages(), [
            ...CustomerRules::attributes(),
            'card_number' => 'número do novo cartão',
        ])->validate();

        return DB::transaction(function () use ($customer, $validated, $user): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            if (! self::canReenroll($customer)) {
                throw ValidationException::withMessages([
                    'card_number' => 'Só titulares sem cartão vigente e com o último cartão cancelado podem reingressar.',
                ]);
            }

            $plan = Plan::query()->active()->whereKey($validated['plan_id'])->lockForUpdate()->first();

            if ($plan === null) {
                throw ValidationException::withMessages(['plan_id' => 'Selecione um plano ativo.']);
            }

            if ($message = self::dependentsLimitMessage($plan, $customer->activeDependents()->count())) {
                throw ValidationException::withMessages(['plan_id' => $message]);
            }

            $fromPlan = $customer->plan;

            $customer->update([
                'plan_id' => $plan->id,
                'due_day' => (int) $validated['due_day'],
            ]);

            $this->assignCard->handle($customer, $validated['card_number'], $user, reason: self::Reason);

            $customer->planChanges()->create([
                'from_plan_id' => $fromPlan->id,
                'to_plan_id' => $plan->id,
                'from_price' => $fromPlan->price,
                'to_price' => $plan->price,
                'reason' => self::Reason,
                'user_id' => $user->id,
            ]);

            $this->issueInvoice->handle($customer, $this->firstUnbilledMonth($customer));

            return $customer;
        });
    }

    /**
     * Whether the holder has no current card and the last card was cancelled.
     */
    public static function canReenroll(Customer $customer): bool
    {
        if ($customer->currentCard()->exists()) {
            return false;
        }

        $lastCard = $customer->lastCard()->first();

        return $lastCard !== null && $lastCard->hasStatus(CardStatusEnum::Cancelled);
    }

    /**
     * Message blocking the re-enrollment when the plan cannot cover the active dependents, or null when it can.
     */
    public static function dependentsLimitMessage(Plan $plan, int $activeDependents): ?string
    {
        $excess = $activeDependents - $plan->max_dependents;

        if ($excess <= 0) {
            return null;
        }

        return "O plano {$plan->name} permite até {$plan->max_dependents} dependentes; inative {$excess} dependente(s) antes do reingresso.";
    }

    /**
     * Month of the next `due_day`, or the first following month without an
     * invoice (invoices kept or cancelled at the cancellation stay put).
     */
    protected function firstUnbilledMonth(Customer $customer): CarbonImmutable
    {
        $month = DueDate::next(today(), $customer->due_day)->startOfMonth();

        while ($customer->invoices()->forMonth($month)->exists()) {
            $month = $month->addMonthNoOverflow();
        }

        return $month;
    }
}

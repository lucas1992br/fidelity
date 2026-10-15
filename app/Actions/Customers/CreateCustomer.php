<?php

namespace App\Actions\Customers;

use App\Actions\Billing\IssueInvoice;
use App\Actions\Cards\AssignCard;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\User;
use App\Support\Billing\DueDate;
use App\Support\Customers\CustomerRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * New sale, all in one transaction: the holder, the sign-up `plan_changes`
 * row, the stock card, the dependents and the first invoice (full monthly
 * amount, due on the next `due_day`). Any failure rolls everything back, so
 * there is never a holder without a card.
 *
 * The monthly amount is typed at the sale; the plan price is only the
 * suggestion, used when no amount is given. A holder sold as a company employee follows the company's due
 * day, and the invoices join the company's consolidated bill.
 */
class CreateCustomer
{
    public function __construct(
        protected AssignCard $assignCard,
        protected AddDependent $addDependent,
        protected IssueInvoice $issueInvoice,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Holder fields, `card_number` and `dependents` (list of dependent inputs).
     *
     * @throws ValidationException
     */
    public function handle(array $input, User $user): Customer
    {
        $input = CustomerRules::normalize($input);
        $input['card_number'] = trim((string) ($input['card_number'] ?? ''));
        $input['dependents'] = array_values((array) ($input['dependents'] ?? []));

        $validated = Validator::make($input, [
            ...CustomerRules::rules(),
            'card_number' => ['required', 'string', 'max:255'],
            'dependents' => ['array'],
        ], CustomerRules::messages(), [
            ...CustomerRules::attributes(),
            'card_number' => 'número do cartão',
        ])->validate();

        return DB::transaction(function () use ($validated, $user): Customer {
            $plan = Plan::query()->active()->whereKey($validated['plan_id'])->lockForUpdate()->first();

            if ($plan === null) {
                throw ValidationException::withMessages(['plan_id' => 'Selecione um plano ativo.']);
            }

            AddDependent::ensureFitsPlan($plan, count($validated['dependents']));

            $validated['monthly_amount'] ??= $plan->price;

            if (filled($validated['company_id'] ?? null)) {
                $company = Company::query()->active()->whereKey($validated['company_id'])->lockForUpdate()->first();

                if ($company === null) {
                    throw ValidationException::withMessages(['company_id' => 'Selecione uma empresa ativa.']);
                }

                $validated['due_day'] = $company->due_day;
            }

            $customer = Customer::create([
                ...Arr::except($validated, ['card_number', 'dependents']),
                'joined_at' => today(),
            ]);

            $customer->planChanges()->create([
                'from_plan_id' => null,
                'to_plan_id' => $plan->id,
                'from_price' => null,
                'to_price' => $validated['monthly_amount'],
                'reason' => 'Adesão',
                'user_id' => $user->id,
            ]);

            $this->assignCard->handle($customer, $validated['card_number'], $user);

            foreach ($validated['dependents'] as $index => $dependent) {
                $this->addDependentAt($customer, (array) $dependent, $index);
            }

            $this->issueInvoice->handle($customer, DueDate::next(today(), $customer->due_day));

            return $customer;
        });
    }

    /**
     * Add one dependent of the sale, reporting its errors under `dependents.{index}.*`.
     *
     * @param  array<string, mixed>  $dependent
     *
     * @throws ValidationException
     */
    protected function addDependentAt(Customer $customer, array $dependent, int $index): void
    {
        try {
            $this->addDependent->handle($customer, $dependent);
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())
                ->mapWithKeys(fn (array $errors, string $key): array => [
                    $key === 'dependents' ? $key : "dependents.{$index}.{$key}" => $errors,
                ])
                ->all();

            throw ValidationException::withMessages($messages);
        }
    }
}

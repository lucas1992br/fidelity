<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\Dependent;
use App\Models\Plan;
use App\Rules\Cpf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Add a dependent within the plan limit (active dependents < max_dependents).
 * The sequence is the highest one ever used by the customer plus one, counting
 * inactive and soft-deleted dependents, so it is never reused.
 */
class AddDependent
{
    /**
     * @param  array{name?: string|null, cpf?: string|null, birth_date?: string|null, kinship_id?: int|string|null}  $input
     *
     * @throws ValidationException
     */
    public function handle(Customer $customer, array $input): Dependent
    {
        $input['cpf'] = filled($input['cpf'] ?? null) ? Cpf::normalize($input['cpf']) : null;
        $input['name'] = trim((string) ($input['name'] ?? ''));

        $validated = Validator::make($input, self::rules(), [], self::attributes())->validate();

        return DB::transaction(function () use ($customer, $validated): Dependent {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            self::ensureFitsPlan($customer->plan, $customer->activeDependents()->count() + 1);

            return $customer->dependents()->create([
                ...$validated,
                'sequence' => (int) Dependent::withTrashed()->where('customer_id', $customer->id)->max('sequence') + 1,
                'is_active' => true,
            ]);
        });
    }

    /**
     * Fail when the plan cannot cover the given number of active dependents.
     *
     * @throws ValidationException
     */
    public static function ensureFitsPlan(Plan $plan, int $activeDependents, string $errorKey = 'dependents'): void
    {
        if ($message = self::limitMessage($plan, $activeDependents)) {
            throw ValidationException::withMessages([$errorKey => $message]);
        }
    }

    /**
     * Message shown when the plan cannot cover the given number of active dependents, or null when it can.
     */
    public static function limitMessage(Plan $plan, int $activeDependents): ?string
    {
        if ($activeDependents <= $plan->max_dependents) {
            return null;
        }

        return $plan->max_dependents === 0
            ? "O plano {$plan->name} não inclui dependentes. Troque de plano para incluir."
            : "O plano {$plan->name} permite até {$plan->max_dependents} dependentes.";
    }

    /**
     * Dependent rules, shared with the `DependentForm`; the CPF must be
     * normalized to digits before validating.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?int $ignoreDependentId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'cpf' => ['nullable', 'string', new Cpf, Rule::unique('dependents', 'cpf')->ignore($ignoreDependentId)],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'kinship_id' => ['required', 'integer', Rule::exists('kinships', 'id')->where('is_active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'name' => 'nome',
            'cpf' => 'CPF',
            'birth_date' => 'data de nascimento',
            'kinship_id' => 'parentesco',
        ];
    }
}

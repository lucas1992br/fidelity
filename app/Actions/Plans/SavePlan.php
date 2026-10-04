<?php

namespace App\Actions\Plans;

use App\Models\Plan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a plan. The price only applies to invoices generated from
 * now on: existing invoices keep the amount frozen when they were generated.
 */
class SavePlan
{
    /**
     * @param  array{name?: string, description?: string|null, price?: string|int|float|null, max_dependents?: int|string|null, is_active?: bool}  $input
     *
     * @throws ValidationException
     */
    public function handle(array $input, ?Plan $plan = null): Plan
    {
        $input['price'] = self::normalizePrice($input['price'] ?? null);
        $input['description'] = filled($input['description'] ?? null) ? trim((string) $input['description']) : null;

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'max_dependents' => ['required', 'integer', 'min:0', 'max:32767'],
            'is_active' => ['required', 'boolean'],
        ], [
            'price.regex' => 'O preço deve ser um valor maior ou igual a zero, com até 2 casas decimais.',
        ], [
            'name' => 'nome',
            'description' => 'descrição',
            'price' => 'preço',
            'max_dependents' => 'limite de dependentes',
            'is_active' => 'ativo',
        ])->validate();

        $plan ??= new Plan;
        $plan->fill($validated)->save();

        return $plan;
    }

    /**
     * Convert a pt-BR money input ("1.234,56") or a decimal ("1234.56") to "1234.56".
     */
    public static function normalizePrice(string|int|float|null $price): ?string
    {
        if ($price === null || $price === '') {
            return null;
        }

        if (is_float($price) || is_int($price)) {
            return number_format((float) $price, 2, '.', '');
        }

        $price = str_replace(['R$', ' '], '', trim($price));

        if (str_contains($price, ',')) {
            $price = str_replace(['.', ','], ['', '.'], $price);
        }

        return $price;
    }
}

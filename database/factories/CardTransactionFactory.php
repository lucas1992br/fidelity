<?php

namespace Database\Factories;

use App\Enums\CardTransactionResult;
use App\Models\CardImport;
use App\Models\CardTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Transactions default to an approved, still pending "Crédito à Vista".
 *
 * @extends Factory<CardTransaction>
 */
class CardTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_import_id' => CardImport::factory(),
            'external_id' => fake()->unique()->numerify('16########'),
            'occurred_at' => now(),
            'type' => 'Crédito à Vista',
            'installments' => 1,
            'brand' => 'Visa',
            'card_bin' => '498407',
            'card_last4' => fake()->numerify('####'),
            'authorization_code' => fake()->numerify('######'),
            'transaction_code' => fake()->numerify('######'),
            'amount' => '89.90',
            'status' => 'Aprovada',
            'result' => CardTransactionResult::Pending,
            'message' => null,
            'payment_id' => null,
        ];
    }
}

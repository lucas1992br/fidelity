<?php

namespace Database\Factories;

use App\Models\CardImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardImport>
 */
class CardImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'file_name' => 'extrato.txt',
            'transactions_count' => 0,
            'duplicates_count' => 0,
            'user_id' => User::factory(),
        ];
    }
}

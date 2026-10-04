<?php

namespace Database\Factories;

use App\Models\CardBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardBatch>
 */
class CardBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $faker = fake('pt_BR');

        return [
            'description' => 'Lote gráfica '.$faker->monthName().'/'.$faker->year(),
            'supplier' => $faker->company(),
            'received_at' => $faker->dateTimeBetween('-1 year')->format('Y-m-d'),
            'quantity' => $faker->numberBetween(50, 500),
            'first_number' => null,
            'last_number' => null,
            'user_id' => User::factory(),
        ];
    }
}

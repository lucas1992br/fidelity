<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\PaymentCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cards default to a holder's card; use `for($company)` with `customer_id`
 * null for a company card.
 *
 * @extends Factory<PaymentCard>
 */
class PaymentCardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'company_id' => null,
            'card_bin' => fake()->numerify('4#####'),
            'card_last4' => fake()->unique()->numerify('####'),
            'brand' => 'Visa',
        ];
    }
}

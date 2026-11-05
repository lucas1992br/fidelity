<?php

namespace Database\Factories;

use App\Actions\Cards\ReenrollCustomer;
use App\Actions\Customers\CreateCustomer;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanChange>
 */
class PlanChangeFactory extends Factory
{
    /**
     * Define the model's default state: the sign-up (no "from" plan).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'from_plan_id' => null,
            'to_plan_id' => fn (array $attributes): int => Customer::withTrashed()->findOrFail((int) $attributes['customer_id'])->plan_id,
            'from_price' => null,
            'to_price' => fn (array $attributes): string => Plan::withTrashed()->findOrFail((int) $attributes['to_plan_id'])->price,
            'reason' => null,
            'user_id' => User::factory(),
        ];
    }

    /**
     * Change from the given plan to the customer's current plan.
     */
    public function from(Plan $plan): static
    {
        return $this->state(fn (array $attributes) => [
            'from_plan_id' => $plan->id,
            'from_price' => $plan->price,
            'reason' => fake('pt_BR')->sentence(),
        ]);
    }

    /**
     * Sale made by the user, as CreateCustomer records it.
     */
    public function sale(): static
    {
        return $this->state(fn (array $attributes) => [
            'reason' => CreateCustomer::Reason,
        ]);
    }

    /**
     * Re-enrollment made by the user, as ReenrollCustomer records it.
     */
    public function reenrollment(): static
    {
        return $this->state(fn (array $attributes) => [
            'reason' => ReenrollCustomer::Reason,
        ]);
    }
}

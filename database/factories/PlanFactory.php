<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Plano '.fake('pt_BR')->unique()->word(),
            'description' => fake('pt_BR')->sentence(),
            'price' => fake()->randomFloat(2, 29, 199),
            'max_dependents' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Plan no longer offered in new sign-ups and plan changes.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Plan that covers up to the given number of dependents.
     */
    public function withDependents(int $maxDependents): static
    {
        return $this->state(fn (array $attributes) => [
            'max_dependents' => $maxDependents,
        ]);
    }
}

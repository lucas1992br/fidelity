<?php

namespace Database\Factories;

use App\Enums\Kinship as KinshipEnum;
use App\Models\Customer;
use App\Models\Dependent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dependent>
 */
class DependentFactory extends Factory
{
    /**
     * Last sequence handed out per customer, so a batch made in one go
     * (before any row is saved) still gets distinct sequences.
     *
     * @var array<int, int>
     */
    protected static array $lastSequences = [];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $faker = fake('pt_BR');

        return [
            'customer_id' => Customer::factory(),
            'kinship_id' => fn (): int => KinshipEnum::Child->id(),
            'name' => $faker->name(),
            'cpf' => null,
            'birth_date' => $faker->dateTimeBetween('-60 years', '-1 year')->format('Y-m-d'),
            'sequence' => fn (array $attributes): int => self::nextSequence((int) $attributes['customer_id']),
            'is_active' => true,
        ];
    }

    /**
     * Dependent no longer covered by the plan.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Dependent with a valid CPF.
     */
    public function withCpf(): static
    {
        return $this->state(fn (array $attributes) => [
            'cpf' => CustomerFactory::cpf(),
        ]);
    }

    protected static function nextSequence(int $customerId): int
    {
        $stored = (int) Dependent::withTrashed()->where('customer_id', $customerId)->max('sequence');

        return self::$lastSequences[$customerId] = max($stored, self::$lastSequences[$customerId] ?? 0) + 1;
    }
}

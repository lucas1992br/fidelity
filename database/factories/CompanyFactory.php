<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
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
            'name' => $faker->company().' LTDA',
            'trade_name' => null,
            'cnpj' => self::cnpj(),
            'contact_name' => $faker->name(),
            'email' => $faker->unique()->companyEmail(),
            'phone' => $faker->numerify('113#######'),
            'due_day' => $faker->numberBetween(1, 28),
            'monthly_amount' => fake()->randomFloat(2, 300, 3000),
            'notes' => null,
            'is_active' => true,
        ];
    }

    /**
     * Company that no longer receives new employees.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * A valid, unique 14-digit CNPJ with both check digits computed.
     */
    public static function cnpj(): string
    {
        do {
            $base = fake()->unique()->numerify('########').'0001';
        } while (count(array_unique(str_split($base))) === 1);

        $digits = $base.self::cnpjCheckDigit($base);

        return $digits.self::cnpjCheckDigit($digits);
    }

    protected static function cnpjCheckDigit(string $digits): int
    {
        $weight = strlen($digits) - 7;
        $sum = 0;

        foreach (str_split($digits) as $digit) {
            $sum += (int) $digit * $weight--;

            if ($weight < 2) {
                $weight = 9;
            }
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }
}

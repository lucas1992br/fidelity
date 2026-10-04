<?php

namespace Database\Factories;

use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Brazilian state abbreviations (UF).
     *
     * @var list<string>
     */
    protected const array STATES = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $faker = fake('pt_BR');

        return [
            'plan_id' => Plan::factory(),
            'name' => $faker->name(),
            'cpf' => self::cpf(),
            'birth_date' => $faker->dateTimeBetween('-80 years', '-18 years')->format('Y-m-d'),
            'email' => $faker->unique()->safeEmail(),
            'phone' => $faker->numerify('119########'),
            'phone_secondary' => null,
            'zip_code' => $faker->numerify('########'),
            'street' => $faker->streetName(),
            'number' => (string) $faker->buildingNumber(),
            'complement' => null,
            'district' => $faker->citySuffix(),
            'city' => $faker->city(),
            'state' => $faker->randomElement(self::STATES),
            'due_day' => $faker->numberBetween(1, 28),
            'joined_at' => $faker->dateTimeBetween('-2 years')->format('Y-m-d'),
        ];
    }

    /**
     * Customer with a card linked in the given status (current when active or suspended).
     */
    public function withCard(CardStatusEnum $status = CardStatusEnum::Active): static
    {
        return $this->afterCreating(function (Customer $customer) use ($status): void {
            Card::factory()->for($customer)->status($status)->create();
        });
    }

    /**
     * A valid, unique 11-digit CPF with both check digits computed.
     */
    public static function cpf(): string
    {
        do {
            $base = fake()->unique()->numerify('#########');
        } while (count(array_unique(str_split($base))) === 1);

        $digits = $base.self::cpfCheckDigit($base);

        return $digits.self::cpfCheckDigit($digits);
    }

    protected static function cpfCheckDigit(string $digits): int
    {
        $weight = strlen($digits) + 1;
        $sum = 0;

        foreach (str_split($digits) as $digit) {
            $sum += (int) $digit * $weight--;
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }
}

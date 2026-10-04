<?php

namespace Database\Factories;

use App\Enums\EntryType as EntryTypeEnum;
use App\Models\FinancialCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialCategory>
 */
class FinancialCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entry_type_id' => fn (): int => EntryTypeEnum::Expense->id(),
            'name' => rtrim(fake('pt_BR')->unique()->sentence(2), '.'),
            'is_system' => false,
            'is_active' => true,
        ];
    }

    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'entry_type_id' => EntryTypeEnum::Income->id(),
        ]);
    }

    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'entry_type_id' => EntryTypeEnum::Expense->id(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

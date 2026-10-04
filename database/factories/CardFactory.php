<?php

namespace Database\Factories;

use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Card;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cards default to stock. Every status state keeps `is_current` coherent
 * with the status and links a customer when the status requires one.
 *
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_batch_id' => null,
            'number' => fake()->unique()->numerify('######'),
            'customer_id' => null,
            'card_status_id' => fn (): int => CardStatusEnum::InStock->id(),
            'is_current' => false,
            'assigned_at' => null,
            'status_changed_at' => null,
        ];
    }

    public function inStock(): static
    {
        return $this->status(CardStatusEnum::InStock);
    }

    public function active(): static
    {
        return $this->status(CardStatusEnum::Active);
    }

    public function suspended(): static
    {
        return $this->status(CardStatusEnum::Suspended);
    }

    public function cancelled(): static
    {
        return $this->status(CardStatusEnum::Cancelled);
    }

    public function lost(): static
    {
        return $this->status(CardStatusEnum::Lost);
    }

    public function discarded(): static
    {
        return $this->status(CardStatusEnum::Discarded);
    }

    /**
     * Card in the given status. Stock cards have no customer; current and
     * cancelled cards belong to one (a new customer unless `for()` is used).
     */
    public function status(CardStatusEnum $status): static
    {
        return $this->state(function (array $attributes) use ($status): array {
            if ($status === CardStatusEnum::InStock) {
                return [
                    'card_status_id' => $status->id(),
                    'customer_id' => null,
                    'is_current' => false,
                    'assigned_at' => null,
                    'status_changed_at' => null,
                ];
            }

            $needsCustomer = in_array($status, [...CardStatusEnum::current(), CardStatusEnum::Cancelled], true);

            return [
                'card_status_id' => $status->id(),
                'customer_id' => $attributes['customer_id'] ?? ($needsCustomer ? Customer::factory() : null),
                'is_current' => in_array($status, CardStatusEnum::current(), true),
                'assigned_at' => $attributes['assigned_at'] ?? now()->subMonths(2)->toDateString(),
                'status_changed_at' => now(),
            ];
        });
    }
}

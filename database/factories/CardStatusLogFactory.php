<?php

namespace Database\Factories;

use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Card;
use App\Models\CardStatusLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardStatusLog>
 */
class CardStatusLogFactory extends Factory
{
    /**
     * Define the model's default state: the card entering stock.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            'from_card_status_id' => null,
            'to_card_status_id' => fn (): int => CardStatusEnum::InStock->id(),
            'reason' => null,
            'user_id' => User::factory(),
        ];
    }

    /**
     * Change between the given statuses.
     */
    public function transition(?CardStatusEnum $from, CardStatusEnum $to): static
    {
        return $this->state(fn (array $attributes) => [
            'from_card_status_id' => $from?->id(),
            'to_card_status_id' => $to->id(),
        ]);
    }

    /**
     * Change made by the system (no user), e.g. the automatic suspension.
     */
    public function bySystem(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }
}

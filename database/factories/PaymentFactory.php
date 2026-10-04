<?php

namespace Database\Factories;

use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The receipt number is left to the database sequence; call refresh() on the
 * created payment to read it.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'paid_at' => today()->toDateString(),
            'amount' => fn (array $attributes): string => Invoice::findOrFail((int) $attributes['invoice_id'])->amount,
            'payment_method_id' => fn (): int => PaymentMethodEnum::Pix->id(),
            'notes' => null,
            'user_id' => User::factory(),
            'refunded_at' => null,
            'refunded_by_user_id' => null,
            'refund_reason' => null,
        ];
    }

    /**
     * Payment made with the given method.
     */
    public function method(PaymentMethodEnum $method): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_method_id' => $method->id(),
        ]);
    }

    /**
     * Payment already refunded (US-9.4).
     */
    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'refunded_at' => now(),
            'refunded_by_user_id' => User::factory(),
            'refund_reason' => fake('pt_BR')->sentence(),
        ]);
    }
}

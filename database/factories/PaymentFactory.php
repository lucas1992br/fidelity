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
     * Link the payment to its invoice in `invoice_payment`, as RegisterPayment does.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Payment $payment): void {
            $payment->invoices()->syncWithoutDetaching([$payment->invoice_id]);
        });
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
     * Consolidated payment of the invoice's company bill (RegisterCompanyPayment).
     */
    public function forCompanyBill(): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => Invoice::findOrFail((int) $attributes['invoice_id'])->company_id,
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

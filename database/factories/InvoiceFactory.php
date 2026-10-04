<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Invoices default to open, for the current month, with the customer's plan
 * and price. `paid()` only flags the invoice; create the Payment separately.
 *
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'plan_id' => fn (array $attributes): int => Customer::withTrashed()->findOrFail((int) $attributes['customer_id'])->plan_id,
            'reference_month' => today()->startOfMonth()->toDateString(),
            'amount' => fn (array $attributes): string => Plan::withTrashed()->findOrFail((int) $attributes['plan_id'])->price,
            'due_date' => today()->startOfMonth()->addDays(9)->toDateString(),
            'invoice_status_id' => fn (): int => InvoiceStatusEnum::Open->id(),
            'paid_at' => null,
        ];
    }

    /**
     * Open invoice not yet due.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_status_id' => InvoiceStatusEnum::Open->id(),
            'due_date' => today()->addDays(10)->toDateString(),
            'paid_at' => null,
        ]);
    }

    /**
     * Overdue invoice, due the given number of days ago.
     */
    public function overdue(int $days = 1): static
    {
        $dueDate = today()->subDays($days);

        return $this->state(fn (array $attributes) => [
            'invoice_status_id' => InvoiceStatusEnum::Overdue->id(),
            'reference_month' => $dueDate->startOfMonth()->toDateString(),
            'due_date' => $dueDate->toDateString(),
            'paid_at' => null,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_status_id' => InvoiceStatusEnum::Paid->id(),
            'paid_at' => $attributes['due_date'],
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_status_id' => InvoiceStatusEnum::Cancelled->id(),
            'paid_at' => null,
        ]);
    }

    /**
     * Invoice of the given reference month ("2026-10"), due on the 10th.
     */
    public function forMonth(string $month): static
    {
        $referenceMonth = CarbonImmutable::parse(Invoice::referenceMonth($month));

        return $this->state(fn (array $attributes) => [
            'reference_month' => $referenceMonth->toDateString(),
            'due_date' => $referenceMonth->addDays(9)->toDateString(),
        ]);
    }
}

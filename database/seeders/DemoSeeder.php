<?php

namespace Database\Seeders;

use App\Enums\CardStatus as CardStatusEnum;
use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Enums\Kinship as KinshipEnum;
use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Models\Card;
use App\Models\CardBatch;
use App\Models\CardStatusLog;
use App\Models\Customer;
use App\Models\Dependent;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\User;
use App\Settings\ClinicSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demonstration data for local development only (run by DatabaseSeeder when
 * the environment is local). Every row follows the domain rules: one current
 * card per customer, dependents numbered by sequence within the plan limit,
 * and exactly one automatic income entry per payment.
 */
class DemoSeeder extends Seeder
{
    /**
     * Number of card holders by profile.
     *
     * @var array<string, int>
     */
    protected const array PROFILES = [
        'punctual' => 32,
        'late' => 8,
        'suspended' => 6,
        'cancelled' => 4,
    ];

    /**
     * Months of invoices generated, the current one included.
     */
    protected const int MONTHS = 6;

    protected const int STOCK_CARDS = 100;

    /**
     * Unpaid months of a suspended customer, the oldest one well past the
     * suspension limit.
     */
    protected const int SUSPENDED_UNPAID_MONTHS = 3;

    protected User $financeiro;

    protected User $atendente;

    protected CarbonImmutable $today;

    protected int $suspendAfterDays;

    public function run(): void
    {
        if (Customer::withTrashed()->exists()) {
            $this->command?->warn('Customers already exist; skipping the demo data.');

            return;
        }

        $this->today = CarbonImmutable::today();
        $this->suspendAfterDays = app(ClinicSettings::class)->suspend_after_days;

        DB::transaction(function (): void {
            $this->financeiro = $this->demoUser('financeiro@demo.test', 'Fernanda Financeiro', 'financeiro');
            $this->atendente = $this->demoUser('atendente@demo.test', 'Alice Atendente', 'atendente');

            $this->seedCardStock();
            $this->seedCustomers();
            $this->seedExpenses();
        });
    }

    protected function demoUser(string $email, string $name, string $role): User
    {
        $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->syncRoles([$role]);

        return $user;
    }

    /**
     * One batch of cards received a week ago, all still in stock.
     */
    protected function seedCardStock(): void
    {
        $receivedAt = $this->today->subWeek();
        $numbers = array_map(fn (int $index): string => sprintf('%06d', 1000 + $index), range(1, self::STOCK_CARDS));

        $batch = CardBatch::factory()->for($this->financeiro)->create([
            'description' => 'Lote gráfica '.$receivedAt->format('m/Y'),
            'supplier' => 'Gráfica Demonstração',
            'received_at' => $receivedAt->toDateString(),
            'quantity' => self::STOCK_CARDS,
            'first_number' => $numbers[0],
            'last_number' => end($numbers),
        ]);

        foreach ($numbers as $number) {
            $card = Card::factory()->for($batch, 'batch')->inStock()->create(['number' => $number]);

            $this->logCardStatus($card, null, CardStatusEnum::InStock, $receivedAt, $this->financeiro);
        }
    }

    protected function seedCustomers(): void
    {
        $plans = [
            Plan::query()->where('name', 'Individual')->firstOrFail(),
            Plan::query()->where('name', 'Familiar')->firstOrFail(),
        ];

        $cardNumber = 100;

        foreach (self::PROFILES as $profile => $count) {
            for ($index = 0; $index < $count; $index++) {
                $plan = $plans[$index % 2];

                $customer = Customer::factory()->for($plan)->create([
                    'due_day' => fake()->randomElement([5, 10, 10, 10, 15, 20, 25]),
                    'joined_at' => $this->today->subMonths(fake()->numberBetween(self::MONTHS + 1, 30))->toDateString(),
                ]);

                PlanChange::factory()->for($customer)->for($this->atendente)->create([
                    'created_at' => $customer->joined_at,
                ]);

                $this->seedDependents($customer, $plan);
                $this->seedCard($customer, $profile, sprintf('%06d', ++$cardNumber));
                $this->seedInvoices($customer, $plan, $profile);
            }
        }
    }

    protected function seedDependents(Customer $customer, Plan $plan): void
    {
        $count = fake()->numberBetween(0, $plan->max_dependents);

        for ($index = 0; $index < $count; $index++) {
            Dependent::factory()->for($customer)->create([
                'kinship_id' => fake()->randomElement(KinshipEnum::cases())->id(),
            ]);
        }
    }

    /**
     * The customer's card with its whole status history: entry in stock,
     * activation on sign-up and, depending on the profile, the automatic
     * suspension or the cancellation.
     */
    protected function seedCard(Customer $customer, string $profile, string $number): void
    {
        $joinedAt = CarbonImmutable::parse($customer->joined_at);

        $status = match ($profile) {
            'suspended' => CardStatusEnum::Suspended,
            'cancelled' => CardStatusEnum::Cancelled,
            default => CardStatusEnum::Active,
        };

        $changedAt = match ($status) {
            CardStatusEnum::Suspended => $this->oldestUnpaidDueDate($customer)->addDays($this->suspendAfterDays + 1),
            CardStatusEnum::Cancelled => $this->cancelledAt(),
            default => $joinedAt,
        };

        $card = Card::factory()->for($customer)->status($status)->create([
            'number' => $number,
            'assigned_at' => $joinedAt->toDateString(),
            'status_changed_at' => $changedAt,
        ]);

        $this->logCardStatus($card, null, CardStatusEnum::InStock, $joinedAt->subDays(7), $this->financeiro);
        $this->logCardStatus($card, CardStatusEnum::InStock, CardStatusEnum::Active, $joinedAt, $this->atendente);

        if ($status === CardStatusEnum::Suspended) {
            $this->logCardStatus($card, CardStatusEnum::Active, $status, $changedAt, null, 'Suspensão automática: '.($this->suspendAfterDays + 1).' dias em atraso');
        }

        if ($status === CardStatusEnum::Cancelled) {
            $this->logCardStatus($card, CardStatusEnum::Active, $status, $changedAt, $this->financeiro, 'Cancelamento a pedido do titular');
        }
    }

    /**
     * Monthly invoices of the last months. Paid ones get their payment and
     * automatic income entry; the unpaid ones are open or overdue by due date.
     * Late customers owe only what is still under the suspension limit;
     * suspended ones owe the last months, the oldest past that limit.
     */
    protected function seedInvoices(Customer $customer, Plan $plan, string $profile): void
    {
        $lateSince = $this->today->subDays(fake()->numberBetween(1, $this->suspendAfterDays - 5));

        for ($monthsAgo = self::MONTHS - 1; $monthsAgo >= 0; $monthsAgo--) {
            $referenceMonth = $this->today->startOfMonth()->subMonths($monthsAgo);
            $dueDate = $referenceMonth->setDay($customer->due_day);

            if ($profile === 'cancelled' && $referenceMonth->greaterThanOrEqualTo($this->cancelledAt()->startOfMonth())) {
                break;
            }

            $isDue = $dueDate->lessThan($this->today);

            $invoice = Invoice::factory()->for($customer)->for($plan)->create([
                'reference_month' => $referenceMonth->toDateString(),
                'amount' => $plan->price,
                'due_date' => $dueDate->toDateString(),
                'invoice_status_id' => ($isDue ? InvoiceStatusEnum::Overdue : InvoiceStatusEnum::Open)->id(),
            ]);

            $isUnpaid = match ($profile) {
                'suspended' => $monthsAgo < self::SUSPENDED_UNPAID_MONTHS,
                'late' => ! $isDue || $dueDate->greaterThanOrEqualTo($lateSince),
                default => ! $isDue && fake()->boolean(70),
            };

            if (! $isUnpaid) {
                $this->pay($invoice, $dueDate);
            }
        }
    }

    /**
     * Register the payment (receipt from the sequence) and its income entry,
     * mirroring what RegisterPayment does.
     */
    protected function pay(Invoice $invoice, CarbonImmutable $dueDate): void
    {
        $paidAt = $dueDate->addDays(fake()->numberBetween(-5, 12));

        if ($paidAt->greaterThan($this->today)) {
            $paidAt = $this->today;
        }

        $payment = Payment::factory()->for($invoice)->for($this->financeiro)
            ->method(fake()->randomElement(PaymentMethodEnum::cases()))
            ->create(['paid_at' => $paidAt->toDateString(), 'amount' => $invoice->amount]);

        FinancialEntry::factory()->automatic($payment)->create();

        $invoice->update([
            'invoice_status_id' => InvoiceStatusEnum::Paid->id(),
            'paid_at' => $paidAt->toDateString(),
        ]);
    }

    /**
     * Monthly rent and a few other manual expenses, paid when already due.
     */
    protected function seedExpenses(): void
    {
        $rent = FinancialCategory::query()->where('name', 'Aluguel')->firstOrFail();
        $others = FinancialCategory::query()->whereIn('name', ['Materiais', 'Marketing', 'Impostos', 'Salários'])->get();

        for ($monthsAgo = self::MONTHS - 1; $monthsAgo >= 0; $monthsAgo--) {
            $month = $this->today->startOfMonth()->subMonths($monthsAgo);

            $this->expense($rent, 'Aluguel da sala '.$month->format('m/Y'), '2500.00', $month->setDay(5), 'Imobiliária Central');
            $this->expense($others->random(), 'Despesa '.$month->format('m/Y'), (string) fake()->randomFloat(2, 80, 900), $month->setDay(fake()->numberBetween(1, 28)), fake('pt_BR')->company());
        }
    }

    protected function expense(FinancialCategory $category, string $description, string $amount, CarbonImmutable $dueDate, string $supplier): void
    {
        $factory = FinancialEntry::factory()->for($category, 'category')->for($this->financeiro)->state([
            'description' => $description,
            'amount' => $amount,
            'due_date' => $dueDate->toDateString(),
            'supplier_or_payer' => $supplier,
        ]);

        ($dueDate->lessThanOrEqualTo($this->today) ? $factory->paid() : $factory->pending())->create();
    }

    protected function logCardStatus(Card $card, ?CardStatusEnum $from, CardStatusEnum $to, CarbonImmutable $at, ?User $user, ?string $reason = null): void
    {
        CardStatusLog::factory()->for($card)->transition($from, $to)->create([
            'user_id' => $user?->id,
            'reason' => $reason,
            'created_at' => $at,
        ]);
    }

    /**
     * Cancelled cards were cancelled three months ago.
     */
    protected function cancelledAt(): CarbonImmutable
    {
        return $this->today->subMonths(3);
    }

    /**
     * Due date of the oldest invoice a suspended customer still owes.
     */
    protected function oldestUnpaidDueDate(Customer $customer): CarbonImmutable
    {
        return $this->today->startOfMonth()->subMonths(self::SUSPENDED_UNPAID_MONTHS - 1)->setDay($customer->due_day);
    }
}

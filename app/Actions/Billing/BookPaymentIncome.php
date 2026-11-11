<?php

namespace App\Actions\Billing;

use App\Enums\EntryStatus as EntryStatusEnum;
use App\Enums\EntryType as EntryTypeEnum;
use App\Models\CardFee;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Payment;
use App\Models\User;
use App\Settings\ClinicSettings;
use Carbon\CarbonInterface;

/**
 * Book the income of a payment in the "Mensalidades" category, shared by the
 * holder's payment and the company's consolidated payment.
 *
 * A card payment (one-off or recurring, at once or in installments) is owed
 * by the acquirer, not by the payer: the clinic anticipates the sale, so it
 * becomes one pending income from the acquirer for the whole amount, due
 * `card_settlement_days` after the payment, plus the card machine fee as a
 * pending expense linked to it (both are settled together). Other payments
 * are one paid income at once, or one pending income per installment (a
 * boleto split by the payer), due monthly from the payment date and settled
 * in accounts receivable. Cents left over by the split go to the first
 * installment.
 */
class BookPaymentIncome
{
    public function __construct(protected ClinicSettings $settings) {}

    public function handle(Payment $payment, string $description, string $payer, CarbonInterface $dueDate, User $user): void
    {
        $entry = [
            'entry_type_id' => EntryTypeEnum::Income->id(),
            'financial_category_id' => FinancialCategory::monthlyFees()->id,
            'payment_method_id' => $payment->payment_method_id,
            'payment_id' => $payment->id,
            'supplier_or_payer' => $payer,
            'user_id' => $user->id,
        ];

        if ($payment->isCardPayment()) {
            $this->bookCardSale($payment, $entry, $description, $payer, $user);

            return;
        }

        if (! $payment->isInstallment()) {
            FinancialEntry::create([
                ...$entry,
                'description' => $description,
                'amount' => $payment->amount,
                'due_date' => $dueDate,
                'entry_status_id' => EntryStatusEnum::Paid->id(),
                'paid_at' => $payment->paid_at,
                'installment_number' => 1,
            ]);

            return;
        }

        foreach ($this->splitAmount($payment->amount, $payment->installments) as $index => $amount) {
            $number = $index + 1;

            FinancialEntry::create([
                ...$entry,
                'description' => "{$description} (parcela {$number}/{$payment->installments})",
                'amount' => $amount,
                'due_date' => $payment->paid_at->copy()->addMonthsNoOverflow($index),
                'entry_status_id' => EntryStatusEnum::Pending->id(),
                'paid_at' => null,
                'installment_number' => $number,
            ]);
        }
    }

    /**
     * Pending income from the acquirer for the whole sale and its card machine fee.
     *
     * @param  array<string, mixed>  $entry
     */
    protected function bookCardSale(Payment $payment, array $entry, string $description, string $payer, User $user): void
    {
        $dueDate = $payment->paid_at->copy()->addDays($this->settings->card_settlement_days);

        $income = FinancialEntry::create([
            ...$entry,
            'description' => "{$description} · cartão {$payment->cardModality()}",
            'amount' => $payment->amount,
            'due_date' => $dueDate,
            'entry_status_id' => EntryStatusEnum::Pending->id(),
            'paid_at' => null,
            'installment_number' => 1,
            'supplier_or_payer' => CardFee::Acquirer,
            'notes' => "Pago por {$payer} no cartão {$payment->cardModality()}; repasse antecipado da operadora.",
        ]);

        if ($payment->card_fee_amount === null || bccomp($payment->card_fee_amount, '0', 2) <= 0) {
            return;
        }

        FinancialEntry::create([
            'entry_type_id' => EntryTypeEnum::Expense->id(),
            'financial_category_id' => FinancialCategory::cardFees()->id,
            'description' => 'Taxa da maquininha '.str_replace('.', ',', $payment->card_fee_rate)."% · cartão {$payment->cardModality()} - {$payer}",
            'amount' => $payment->card_fee_amount,
            'due_date' => $dueDate,
            'entry_status_id' => EntryStatusEnum::Pending->id(),
            'supplier_or_payer' => CardFee::Acquirer,
            'related_entry_id' => $income->id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * Split the amount into equal installments; the leftover cents go to the first one.
     *
     * @return list<string>
     */
    protected function splitAmount(string $amount, int $installments): array
    {
        $share = bcdiv($amount, (string) $installments, 2);
        $first = bcsub($amount, bcmul($share, (string) ($installments - 1), 2), 2);

        return [$first, ...array_fill(0, $installments - 1, $share)];
    }
}

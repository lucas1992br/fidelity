<?php

namespace App\Actions\Billing;

use App\Enums\EntryStatus as EntryStatusEnum;
use App\Enums\EntryType as EntryTypeEnum;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Book the income of a payment in the "Mensalidades" category, shared by the
 * holder's payment and the company's consolidated payment: one paid income
 * for a single payment; otherwise one pending income per installment, due
 * monthly from the payment date and settled in accounts receivable. Cents
 * left over by the split go to the first installment.
 */
class BookPaymentIncome
{
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

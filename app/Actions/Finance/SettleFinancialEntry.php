<?php

namespace App\Actions\Finance;

use App\Actions\Billing\PaymentWillReactivateCard;
use App\Actions\Cards\ReactivateCard;
use App\Enums\EntryStatus as EntryStatusEnum;
use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Models\Card;
use App\Models\Customer;
use App\Models\FinancialEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mark a pending entry as paid or received (US-10.4): it enters the cash flow
 * on the informed date. Settling a card sale received from the acquirer also
 * settles its pending card machine fee, since the deposit comes net of it.
 * Settling a boleto installment reactivates the suspended card of each holder
 * it bound (the employees, for a company's bill) once nothing else is late.
 * The row is locked, so two settlements of the same entry result in a single one.
 */
class SettleFinancialEntry
{
    public function __construct(
        protected PaymentWillReactivateCard $paymentWillReactivateCard,
        protected ReactivateCard $reactivateCard,
    ) {}

    /**
     * @param  array{paid_at?: mixed, payment_method?: mixed}  $input  Date as Y-m-d, method slug.
     *
     * @throws ValidationException
     */
    public function handle(FinancialEntry $entry, array $input): FinancialEntry
    {
        $validated = Validator::make($input, [
            'paid_at' => ['required', 'date_format:Y-m-d'],
            'payment_method' => ['required', Rule::exists('payment_methods', 'slug')->where('is_active', true)],
        ], [], [
            'paid_at' => 'data do pagamento',
            'payment_method' => 'forma de pagamento',
        ])->validate();

        return DB::transaction(function () use ($entry, $validated): FinancialEntry {
            $entry = FinancialEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->entry_status_id !== EntryStatusEnum::Pending->id()) {
                throw ValidationException::withMessages(['entry' => 'Este lançamento já foi baixado ou cancelado.']);
            }

            $settlement = [
                'entry_status_id' => EntryStatusEnum::Paid->id(),
                'paid_at' => $validated['paid_at'],
                'payment_method_id' => PaymentMethodEnum::from($validated['payment_method'])->id(),
            ];

            $entry->update($settlement);

            $entry->fees()
                ->where('entry_status_id', EntryStatusEnum::Pending->id())
                ->lockForUpdate()
                ->get()
                ->each(fn (FinancialEntry $fee): bool => $fee->update($settlement));

            $this->reactivateBoundHolders($entry);

            return $entry;
        });
    }

    /**
     * Reactivate the suspended cards the settled boleto installment kept suspended.
     */
    protected function reactivateBoundHolders(FinancialEntry $entry): void
    {
        $payment = $entry->payment;

        if ($payment === null || $payment->payment_method_id !== PaymentMethodEnum::Boleto->id()) {
            return;
        }

        Customer::query()
            ->whereIn('id', $payment->invoices()->select('invoices.customer_id'))
            ->get()
            ->filter(fn (Customer $customer): bool => $this->paymentWillReactivateCard->forCustomer($customer))
            ->each(fn (Customer $customer): Card => $this->reactivateCard->handle($customer->currentCard()->firstOrFail(), ReactivateCard::PaymentReason));
    }
}

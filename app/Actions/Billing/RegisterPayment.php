<?php

namespace App\Actions\Billing;

use App\Actions\Cards\ReactivateCard;
use App\Enums\EntryStatus as EntryStatusEnum;
use App\Enums\EntryType as EntryTypeEnum;
use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Register the payment of an invoice (US-9.1): the payment gets the next
 * receipt number, the invoice becomes `paid` and a paid income entry is
 * booked in the "Mensalidades" category. A suspended card in force is
 * reactivated when the holder is no longer delinquent (US-9.2).
 *
 * The invoice row is locked, so two payments of the same invoice result in a
 * single payment. The amount is compared as a decimal, never as a float.
 */
class RegisterPayment
{
    public function __construct(
        protected PaymentWillReactivateCard $paymentWillReactivateCard,
        protected ReactivateCard $reactivateCard,
    ) {}

    /**
     * @param  array{paid_at?: mixed, amount?: mixed, payment_method?: mixed, notes?: mixed}  $input  Date as Y-m-d, amount as "89.90", method slug.
     *
     * @throws ValidationException
     */
    public function handle(Invoice $invoice, array $input, User $user): Payment
    {
        $validated = Validator::make($input, [
            'paid_at' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'payment_method' => ['required', Rule::exists('payment_methods', 'slug')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'paid_at' => 'data do pagamento',
            'amount' => 'valor recebido',
            'payment_method' => 'forma de pagamento',
            'notes' => 'observação',
        ])->validate();

        return DB::transaction(function () use ($invoice, $validated, $user): Payment {
            $invoice = Invoice::query()->with('customer')->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->hasStatus(InvoiceStatusEnum::Paid)) {
                throw ValidationException::withMessages(['invoice' => 'Esta mensalidade já foi paga.']);
            }

            if ($invoice->hasStatus(InvoiceStatusEnum::Cancelled)) {
                throw ValidationException::withMessages(['invoice' => 'Esta mensalidade está cancelada.']);
            }

            if (bccomp($validated['amount'], $invoice->amount, 2) < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'O valor recebido não pode ser menor que '.Format::money($invoice->amount).'.',
                ]);
            }

            $paymentMethodId = PaymentMethodEnum::from($validated['payment_method'])->id();

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'paid_at' => $validated['paid_at'],
                'amount' => $validated['amount'],
                'payment_method_id' => $paymentMethodId,
                'notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null,
                'user_id' => $user->id,
            ])->refresh();

            $invoice->forceFill([
                'invoice_status_id' => InvoiceStatusEnum::Paid->id(),
                'paid_at' => $payment->paid_at,
            ])->save();

            FinancialEntry::create([
                'entry_type_id' => EntryTypeEnum::Income->id(),
                'financial_category_id' => FinancialCategory::monthlyFees()->id,
                'description' => "Mensalidade {$invoice->reference_month->format('m/Y')} - {$invoice->customer->name}",
                'amount' => $payment->amount,
                'due_date' => $invoice->due_date,
                'entry_status_id' => EntryStatusEnum::Paid->id(),
                'paid_at' => $payment->paid_at,
                'payment_method_id' => $paymentMethodId,
                'payment_id' => $payment->id,
                'supplier_or_payer' => $invoice->customer->name,
                'user_id' => $user->id,
            ]);

            if ($this->paymentWillReactivateCard->handle($invoice)) {
                $this->reactivateCard->handle($invoice->customer->currentCard()->firstOrFail(), ReactivateCard::PaymentReason);
            }

            return $payment;
        });
    }
}

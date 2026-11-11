<?php

namespace App\Actions\Billing;

use App\Actions\Cards\ReactivateCard;
use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Models\CardFee;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Format;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
 * One payment may cover up to 12 consecutive months (e.g. a year of plan)
 * starting at the invoice's month: months not invoiced yet are issued at the
 * holder's monthly amount, every covered invoice becomes `paid` and is linked to
 * the payment, and the amount must reach the sum of the months.
 *
 * Card and boleto payments may be split into up to 12 installments: the
 * invoices are still paid at once. A card payment (one-off or recurring;
 * debit when `card_debit`) freezes the card machine rate of its modality and
 * its fee, and is received from the acquirer at once; a boleto's
 * installments are pending incomes from the payer ({@see BookPaymentIncome}).
 *
 * Invoices of a company's consolidated bill are paid only through
 * {@see RegisterCompanyPayment}.
 *
 * A card payment may record the payer's card (first 6 and last 4 digits
 * only), remembered on the holder for the card reconciliation.
 *
 * The covered invoice rows are locked, so two payments of the same month
 * result in a single payment. Amounts are compared as decimals, never floats.
 */
class RegisterPayment
{
    public function __construct(
        protected PaymentWillReactivateCard $paymentWillReactivateCard,
        protected ReactivateCard $reactivateCard,
        protected RememberPaymentCard $rememberPaymentCard,
        protected IssueInvoice $issueInvoice,
        protected PaymentPeriodAmount $paymentPeriodAmount,
        protected BookPaymentIncome $bookPaymentIncome,
    ) {}

    /**
     * @param  array{paid_at?: mixed, amount?: mixed, payment_method?: mixed, months?: mixed, installments?: mixed, card_digits?: mixed, card_brand?: mixed, card_debit?: mixed, notes?: mixed}  $input  Date as Y-m-d, amount as "89.90", method slug, months and installments 1-12 (default 1); card payments may carry the payer's card as first 6 + last 4 digits ("498407-9593") and its brand.
     *
     * @throws ValidationException
     */
    public function handle(Invoice $invoice, array $input, User $user): Payment
    {
        $validated = Validator::make(['months' => 1, 'installments' => 1, ...$input], [
            'paid_at' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'payment_method' => ['required', Rule::exists('payment_methods', 'slug')->where('is_active', true)],
            'months' => ['required', 'integer', 'between:1,'.Payment::MaxMonths],
            'installments' => ['required', 'integer', 'between:1,'.Payment::MaxInstallments],
            'card_digits' => ['nullable', 'string', 'max:25'],
            'card_brand' => ['nullable', 'string', 'max:50'],
            'card_debit' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'paid_at' => 'data do pagamento',
            'amount' => 'valor recebido',
            'payment_method' => 'forma de pagamento',
            'months' => 'meses',
            'installments' => 'parcelas',
            'card_digits' => 'cartão do pagador',
            'card_brand' => 'bandeira',
            'notes' => 'observação',
        ])->validate();

        $method = PaymentMethodEnum::from($validated['payment_method']);
        $months = (int) $validated['months'];
        $installments = (int) $validated['installments'];

        if ($installments > 1 && ! $method->allowsInstallments()) {
            throw ValidationException::withMessages(['installments' => 'Só é possível parcelar no cartão ou no boleto.']);
        }

        $cardDigits = RememberPaymentCard::typedDigits($validated['card_digits'] ?? null, $method);
        $isDebit = $method->isCreditCard() && (bool) ($validated['card_debit'] ?? false);

        if ($isDebit && $installments > 1) {
            throw ValidationException::withMessages(['installments' => 'Venda no débito não tem parcelas.']);
        }

        $cardFee = $method->isCreditCard() ? CardFee::feeFor($validated['amount'], $isDebit, $installments) : null;

        return DB::transaction(function () use ($invoice, $validated, $method, $months, $installments, $cardDigits, $isDebit, $cardFee, $user): Payment {
            $invoice = Invoice::query()->with('customer')->lockForUpdate()->findOrFail($invoice->id);
            $this->ensurePayable($invoice, 'Esta mensalidade');

            $covered = $this->coveredInvoices($invoice, $months);
            $total = $this->paymentPeriodAmount->handle($invoice, $months);

            if (bccomp($validated['amount'], $total, 2) < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'O valor recebido não pode ser menor que '.Format::money($total).'.',
                ]);
            }

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'paid_at' => $validated['paid_at'],
                'amount' => $validated['amount'],
                'months' => $months,
                'payment_method_id' => $method->id(),
                'installments' => $installments,
                'card_bin' => $cardDigits[0] ?? null,
                'card_last4' => $cardDigits[1] ?? null,
                'is_debit' => $isDebit,
                'card_fee_rate' => $cardFee['rate'] ?? null,
                'card_fee_amount' => $cardFee['amount'] ?? null,
                'notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null,
                'user_id' => $user->id,
            ])->refresh();

            $payment->invoices()->attach($covered->modelKeys());

            Invoice::query()->whereKey($covered->modelKeys())->get()->each(fn (Invoice $coveredInvoice): bool => $coveredInvoice->forceFill([
                'invoice_status_id' => InvoiceStatusEnum::Paid->id(),
                'paid_at' => $payment->paid_at,
            ])->save());

            $invoice->refresh();
            $payment->setRelation('invoice', $invoice);
            $this->bookPaymentIncome->handle(
                $payment,
                ($payment->months === 1 ? 'Mensalidade' : 'Mensalidades')." {$payment->referencePeriod()} - {$invoice->customer->name}",
                $invoice->customer->name,
                $invoice->due_date,
                $user,
            );

            if ($this->paymentWillReactivateCard->handle($invoice)) {
                $this->reactivateCard->handle($invoice->customer->currentCard()->firstOrFail(), ReactivateCard::PaymentReason);
            }

            if ($cardDigits !== null) {
                $this->rememberPaymentCard->handle($invoice->customer, ...$cardDigits, brand: $validated['card_brand'] ?? null);
            }

            return $payment;
        });
    }

    /**
     * @throws ValidationException
     */
    protected function ensurePayable(Invoice $invoice, string $subject): void
    {
        if ($invoice->isCompanyBilled()) {
            throw ValidationException::withMessages(['invoice' => "{$subject} faz parte da fatura da empresa; registre o pagamento na página da empresa."]);
        }

        if ($invoice->hasStatus(InvoiceStatusEnum::Paid)) {
            throw ValidationException::withMessages(['invoice' => "{$subject} já foi paga."]);
        }

        if ($invoice->hasStatus(InvoiceStatusEnum::Cancelled)) {
            throw ValidationException::withMessages(['invoice' => "{$subject} está cancelada."]);
        }
    }

    /**
     * The invoice plus the following months, issued when missing and locked.
     *
     * @return EloquentCollection<int, Invoice>
     *
     * @throws ValidationException
     */
    protected function coveredInvoices(Invoice $invoice, int $months): EloquentCollection
    {
        $covered = new EloquentCollection([$invoice]);

        for ($offset = 1; $offset < $months; $offset++) {
            $referenceMonth = $invoice->reference_month->copy()->addMonthsNoOverflow($offset);
            $this->issueInvoice->handle($invoice->customer, $referenceMonth);

            $next = $invoice->customer->invoices()->forMonth($referenceMonth)->lockForUpdate()->sole();
            $this->ensurePayable($next, "A mensalidade {$referenceMonth->format('m/Y')}");
            $covered->push($next);
        }

        return $covered;
    }
}

<?php

namespace App\Actions\Billing;

use App\Actions\Cards\ReactivateCard;
use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Models\CardFee;
use App\Models\Company;
use App\Models\CompanyBill;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pay a company's consolidated bill of a reference month: one payment with a
 * single receipt covers every open or overdue employee invoice of that month,
 * which become `paid`, and one income (or one per installment) is booked to
 * the company. Each employee's suspended card is reactivated when the
 * employee is no longer delinquent. The amount must reach the bill total: the
 * company's fixed amount frozen on the month's bill (plus the employee
 * invoices, zero since the fixed amount).
 * A card payment may record the payer's card (first 6 and last 4 digits
 * only), remembered on the company for the card reconciliation.
 * Requires `billing.pay`.
 */
class RegisterCompanyPayment
{
    public function __construct(
        protected PaymentWillReactivateCard $paymentWillReactivateCard,
        protected ReactivateCard $reactivateCard,
        protected RememberPaymentCard $rememberPaymentCard,
        protected BookPaymentIncome $bookPaymentIncome,
    ) {}

    /**
     * @param  array{paid_at?: mixed, amount?: mixed, payment_method?: mixed, installments?: mixed, card_digits?: mixed, card_brand?: mixed, card_debit?: mixed, notes?: mixed}  $input  Date as Y-m-d, amount as "899.00", method slug and installments 1-12 (default 1); card payments may carry the payer's card as first 6 + last 4 digits ("498407-9593") and its brand.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Company $company, CarbonInterface|string $referenceMonth, array $input, User $user): Payment
    {
        if (! $user->can('billing.pay')) {
            throw new AuthorizationException;
        }

        $validated = Validator::make(['installments' => 1, ...$input], [
            'paid_at' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'payment_method' => ['required', Rule::exists('payment_methods', 'slug')->where('is_active', true)],
            'installments' => ['required', 'integer', 'between:1,'.Payment::MaxInstallments],
            'card_digits' => ['nullable', 'string', 'max:25'],
            'card_brand' => ['nullable', 'string', 'max:50'],
            'card_debit' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'paid_at' => 'data do pagamento',
            'amount' => 'valor recebido',
            'payment_method' => 'forma de pagamento',
            'installments' => 'parcelas',
            'card_digits' => 'cartão do pagador',
            'card_brand' => 'bandeira',
            'notes' => 'observação',
        ])->validate();

        $method = PaymentMethodEnum::from($validated['payment_method']);
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

        $month = Invoice::referenceMonth($referenceMonth);

        return DB::transaction(function () use ($company, $month, $validated, $method, $installments, $cardDigits, $isDebit, $cardFee, $user): Payment {
            $invoices = $company->invoices()
                ->forMonth($month)
                ->payable()
                ->with('customer')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($invoices->isEmpty()) {
                throw ValidationException::withMessages([
                    'invoice' => 'Não há mensalidades em aberto na fatura de '.Carbon::parse($month)->format('m/Y').'.',
                ]);
            }

            $bill = $company->bills()->whereDate('reference_month', $month)->first();
            $total = CompanyBill::totalOf($bill, $invoices);

            if (bccomp($total, '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'A fatura de '.Carbon::parse($month)->format('m/Y').' está sem valor. Defina o valor fixo mensal da empresa.',
                ]);
            }

            if (bccomp($validated['amount'], $total, 2) < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'O valor recebido não pode ser menor que '.Format::money($total).'.',
                ]);
            }

            $first = $invoices->first();

            $payment = Payment::create([
                'invoice_id' => $first->id,
                'company_id' => $company->id,
                'paid_at' => $validated['paid_at'],
                'amount' => $validated['amount'],
                'months' => 1,
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

            $payment->invoices()->attach($invoices->modelKeys());

            $invoices->each(fn (Invoice $invoice): bool => $invoice->forceFill([
                'invoice_status_id' => InvoiceStatusEnum::Paid->id(),
                'paid_at' => $payment->paid_at,
            ])->save());

            $payment->setRelation('invoice', $first);
            $employees = $invoices->count() === 1 ? '1 funcionário' : "{$invoices->count()} funcionários";
            $this->bookPaymentIncome->handle(
                $payment,
                "Fatura empresa {$payment->referencePeriod()} - {$company->name} ({$employees})",
                $company->name,
                $first->due_date,
                $user,
            );

            foreach ($invoices as $invoice) {
                if ($this->paymentWillReactivateCard->handle($invoice)) {
                    $this->reactivateCard->handle($invoice->customer->currentCard()->firstOrFail(), ReactivateCard::PaymentReason);
                }
            }

            if ($cardDigits !== null) {
                $this->rememberPaymentCard->handle($company, ...$cardDigits, brand: $validated['card_brand'] ?? null);
            }

            return $payment;
        });
    }
}

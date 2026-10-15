<?php

namespace App\Actions\Billing;

use App\Actions\Cards\ReactivateCard;
use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Models\Company;
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
 * employee is no longer delinquent. The amount must reach the bill total.
 * Requires `billing.pay`.
 */
class RegisterCompanyPayment
{
    public function __construct(
        protected PaymentWillReactivateCard $paymentWillReactivateCard,
        protected ReactivateCard $reactivateCard,
        protected BookPaymentIncome $bookPaymentIncome,
    ) {}

    /**
     * @param  array{paid_at?: mixed, amount?: mixed, payment_method?: mixed, installments?: mixed, notes?: mixed}  $input  Date as Y-m-d, amount as "899.00", method slug and installments 1-12 (default 1).
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
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'paid_at' => 'data do pagamento',
            'amount' => 'valor recebido',
            'payment_method' => 'forma de pagamento',
            'installments' => 'parcelas',
            'notes' => 'observação',
        ])->validate();

        $method = PaymentMethodEnum::from($validated['payment_method']);
        $installments = (int) $validated['installments'];

        if ($installments > 1 && ! $method->allowsInstallments()) {
            throw ValidationException::withMessages(['installments' => 'Só é possível parcelar no cartão ou no boleto.']);
        }

        $month = Invoice::referenceMonth($referenceMonth);

        return DB::transaction(function () use ($company, $month, $validated, $method, $installments, $user): Payment {
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

            $total = $invoices->reduce(fn (string $sum, Invoice $invoice): string => bcadd($sum, $invoice->amount, 2), '0.00');

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

            return $payment;
        });
    }
}

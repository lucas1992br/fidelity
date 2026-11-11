<?php

namespace App\Actions\Billing;

use App\Enums\CardTransactionResult;
use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Models\CardTransaction;
use App\Models\Company;
use App\Models\CompanyBill;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCard;
use App\Models\User;
use App\Support\Format;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Settle an approved card transaction of the acquirer statement: the payer
 * is the holder or company that already paid with that card (first 6 + last
 * 4 digits), or the one chosen by the user, whose card is then remembered.
 *
 * The automatic settlement ties the card to the plan value: the amount must
 * be exactly the holder's monthly fee from the oldest open invoice, or the
 * exact total of 1 to 12 consecutive months (e.g. a year on the card), and
 * for a company exactly its oldest open bill. Any other amount on the same
 * card (another purchase, a different charge) stays `pending` and settles
 * nothing. When the user links the payer by hand, an amount above the plan
 * value is accepted, with the leftover counted as interest. The payment is a card payment on the
 * transaction date, with the transaction's installments, debit or credit
 * modality (for the card machine fee) and card. When it
 * cannot be settled (unknown or shared card, employee card, nothing open,
 * amount too low) the transaction stays `pending` with the reason.
 * Requires `billing.pay`.
 */
class ReconcileCardTransaction
{
    public function __construct(
        protected RegisterPayment $registerPayment,
        protected RegisterCompanyPayment $registerCompanyPayment,
        protected PaymentPeriodAmount $paymentPeriodAmount,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(CardTransaction $transaction, User $user, Customer|Company|null $payer = null): CardTransaction
    {
        if (! $user->can('billing.pay')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($transaction, $user, $payer): CardTransaction {
            $transaction = CardTransaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($transaction->result !== CardTransactionResult::Pending) {
                return $transaction;
            }

            try {
                $payment = $this->settle($transaction, $payer ?? $this->rememberedPayer($transaction), $user, exactAmount: $payer === null);
            } catch (ValidationException $exception) {
                $transaction->update(['message' => collect($exception->errors())->flatten()->first()]);

                return $transaction;
            }

            $transaction->update(['result' => CardTransactionResult::Paid, 'message' => null, 'payment_id' => $payment->id]);

            return $transaction;
        });
    }

    /**
     * The only holder or company that paid with the card before.
     *
     * @throws ValidationException
     */
    protected function rememberedPayer(CardTransaction $transaction): Customer|Company
    {
        $cards = PaymentCard::query()->withDigits($transaction->card_bin, $transaction->card_last4)->with(['customer', 'company'])->get();

        if ($cards->isEmpty()) {
            throw ValidationException::withMessages(['payer' => 'Cartão sem cliente ou empresa vinculado. Vincule para dar baixa; nos próximos meses a baixa é automática.']);
        }

        if ($cards->count() > 1) {
            throw ValidationException::withMessages(['payer' => 'Cartão usado por mais de um cliente ou empresa. Vincule manualmente.']);
        }

        return $cards->first()->customer ?? $cards->first()->company;
    }

    /**
     * @throws ValidationException
     */
    protected function settle(CardTransaction $transaction, Customer|Company $payer, User $user, bool $exactAmount): Payment
    {
        if ($transaction->installments > Payment::MaxInstallments) {
            throw ValidationException::withMessages(['installments' => "Venda em {$transaction->installments}x: o sistema aceita até ".Payment::MaxInstallments.' parcelas. Registre o pagamento manualmente.']);
        }

        $input = [
            'paid_at' => $transaction->occurred_at->toDateString(),
            'amount' => $transaction->amount,
            'payment_method' => PaymentMethodEnum::Card->value,
            'installments' => $transaction->isDebit() ? 1 : $transaction->installments,
            'card_debit' => $transaction->isDebit(),
            'card_digits' => $transaction->card_bin.$transaction->card_last4,
            'card_brand' => $transaction->brand,
            'notes' => "Conciliação de cartão · ID Granito {$transaction->external_id}".($transaction->authorization_code ? " · aut. {$transaction->authorization_code}" : ''),
        ];

        if ($payer instanceof Company) {
            return $this->payCompany($payer, $input, $user, $exactAmount);
        }

        if ($payer->isEmployee()) {
            throw ValidationException::withMessages(['payer' => "{$payer->name} é funcionário de {$payer->company->display_name}: vincule o cartão à empresa."]);
        }

        $invoice = $payer->invoices()->payable()->orderBy('reference_month')->orderBy('id')->first()
            ?? throw ValidationException::withMessages(['invoice' => "{$payer->name} não tem mensalidade em aberto."]);

        $months = $exactAmount ? $this->exactMonths($invoice, $transaction->amount) : $this->coveredMonths($invoice, $transaction->amount);

        return $this->registerPayment->handle($invoice, [...$input, 'months' => $months], $user);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    protected function payCompany(Company $company, array $input, User $user, bool $exactAmount): Payment
    {
        $month = $company->invoices()->payable()->orderBy('reference_month')->value('reference_month')
            ?? throw ValidationException::withMessages(['invoice' => "{$company->display_name} não tem fatura em aberto."]);

        if ($exactAmount) {
            $bill = $company->bills()->whereDate('reference_month', Invoice::referenceMonth($month))->first();
            $total = CompanyBill::totalOf($bill, $company->invoices()->forMonth($month)->payable()->get());

            if (bccomp($input['amount'], $total, 2) !== 0) {
                throw ValidationException::withMessages(['amount' => 'Valor de '.Format::money($input['amount']).' não confere com a fatura de '.Format::money($total).' de '.$company->display_name.'. Vincule manualmente se for desta empresa.']);
            }
        }

        return $this->registerCompanyPayment->handle($company, Invoice::referenceMonth($month), $input, $user);
    }

    /**
     * Consecutive months from the invoice (1 to 12) whose total is exactly the
     * amount, stopping before a month already paid or cancelled.
     *
     * @throws ValidationException
     */
    protected function exactMonths(Invoice $invoice, string $amount): int
    {
        for ($months = 1; $months <= Payment::MaxMonths; $months++) {
            if ($months > 1) {
                $next = $invoice->customer->invoices()->forMonth($invoice->reference_month->copy()->addMonthsNoOverflow($months - 1))->first();

                if ($next !== null && ! InvoiceStatusEnum::fromId($next->invoice_status_id)->isPayable()) {
                    break;
                }
            }

            $total = $this->paymentPeriodAmount->handle($invoice, $months);
            $comparison = bccomp($amount, $total, 2);

            if ($comparison === 0) {
                return $months;
            }

            if ($comparison < 0) {
                break;
            }
        }

        throw ValidationException::withMessages([
            'amount' => 'Valor de '.Format::money($amount).' não confere com o plano de '.$invoice->customer->name.' (mensalidade de '.Format::money($this->paymentPeriodAmount->handle($invoice, 1)).'). Vincule manualmente se for deste cliente.',
        ]);
    }

    /**
     * Most consecutive months from the invoice (up to 12) whose total the
     * amount reaches, stopping before a month already paid or cancelled.
     *
     * @throws ValidationException
     */
    protected function coveredMonths(Invoice $invoice, string $amount): int
    {
        $first = $this->paymentPeriodAmount->handle($invoice, 1);

        if (bccomp($amount, $first, 2) < 0) {
            throw ValidationException::withMessages(['amount' => 'Valor de '.Format::money($amount).' menor que a mensalidade de '.Format::money($first).'.']);
        }

        $months = 1;

        while ($months < Payment::MaxMonths) {
            $next = $invoice->customer->invoices()->forMonth($invoice->reference_month->copy()->addMonthsNoOverflow($months))->first();

            if ($next !== null && ! InvoiceStatusEnum::fromId($next->invoice_status_id)->isPayable()) {
                break;
            }

            if (bccomp($amount, $this->paymentPeriodAmount->handle($invoice, $months + 1), 2) < 0) {
                break;
            }

            $months++;
        }

        return $months;
    }
}

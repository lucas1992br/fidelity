<?php

namespace App\Actions\Billing;

use App\Enums\EntryStatus as EntryStatusEnum;
use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Refund a payment registered by mistake (US-9.4, admin only). The payment is
 * kept with the refund stamp, the invoice returns to `open` (not yet due) or
 * `overdue` with `paid_at` cleared, and the linked income entry is cancelled.
 * Nothing is deleted and the refund is recorded in the activity log.
 *
 * The card status is not touched: the daily routine re-evaluates suspension.
 */
class RefundPayment
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Payment $payment, ?string $reason, User $user): Payment
    {
        if (! $user->can('billing.refund')) {
            throw new AuthorizationException;
        }

        $validated = Validator::make(['reason' => filled($reason) ? trim($reason) : null], [
            'reason' => ['nullable', 'string', 'max:255'],
        ], [], ['reason' => 'motivo'])->validate();

        return DB::transaction(function () use ($payment, $validated, $user): Payment {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->isRefunded()) {
                throw ValidationException::withMessages(['payment' => 'Este pagamento já foi estornado.']);
            }

            $payment->forceFill([
                'refunded_at' => now(),
                'refunded_by_user_id' => $user->id,
                'refund_reason' => $validated['reason'],
            ])->save();

            $invoice->forceFill([
                'invoice_status_id' => $invoice->due_date->gte(today())
                    ? InvoiceStatusEnum::Open->id()
                    : InvoiceStatusEnum::Overdue->id(),
                'paid_at' => null,
            ])->save();

            $payment->financialEntry()->first()?->forceFill([
                'entry_status_id' => EntryStatusEnum::Cancelled->id(),
            ])->save();

            activity('billing')
                ->performedOn($payment)
                ->causedBy($user)
                ->event('refunded')
                ->withProperties(['receipt_number' => $payment->receipt_number, 'reason' => $validated['reason']])
                ->log("Estorno do recibo nº {$payment->receipt_number}");

            return $payment;
        });
    }
}

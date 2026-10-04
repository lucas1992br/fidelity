<?php

namespace App\Actions\Cards;

use App\Actions\Billing\CancelInvoice;
use App\Enums\CardStatus as CardStatusEnum;
use App\Models\Card;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cancel the holder's current card for good, in one transaction. The
 * operator decides what happens to the holder's pending invoices: `cancel`
 * them or `keep` them chargeable. A cancelled card never returns to stock,
 * is never reactivated and stops the monthly invoices.
 */
class CancelCard
{
    public const string CancelPendingInvoices = 'cancel';

    public const string KeepPendingInvoices = 'keep';

    public function __construct(
        protected ChangeCardStatus $changeCardStatus,
        protected CancelInvoice $cancelInvoice,
    ) {}

    /**
     * @param  string  $pendingInvoices  {@see self::CancelPendingInvoices} or {@see self::KeepPendingInvoices}.
     *
     * @throws ValidationException
     */
    public function handle(Card $card, ?string $reason, string $pendingInvoices, ?User $user = null): Card
    {
        $validated = Validator::make([
            'reason' => trim((string) $reason),
            'pending_invoices' => $pendingInvoices,
        ], [
            'reason' => ['required', 'string', 'max:255'],
            'pending_invoices' => ['required', Rule::in([self::CancelPendingInvoices, self::KeepPendingInvoices])],
        ], [], [
            'reason' => 'motivo',
            'pending_invoices' => 'destino das faturas pendentes',
        ])->validate();

        return DB::transaction(function () use ($card, $validated, $user): Card {
            $card = Card::query()->lockForUpdate()->findOrFail($card->id);

            if (! $card->is_current || $card->customer_id === null) {
                throw ValidationException::withMessages([
                    'reason' => 'Só cartões ativos ou suspensos podem ser cancelados.',
                ]);
            }

            if ($validated['pending_invoices'] === self::CancelPendingInvoices) {
                Invoice::query()->where('customer_id', $card->customer_id)->payable()->orderBy('id')->get()
                    ->each(fn (Invoice $invoice) => $this->cancelInvoice->handle($invoice));
            }

            $this->changeCardStatus->handle($card, CardStatusEnum::Cancelled, $validated['reason'], $user);

            return $card;
        });
    }
}

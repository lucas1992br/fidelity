<?php

namespace App\Services;

use App\Enums\CardStatus;
use App\Enums\PaymentMethod;
use App\Models\Card;
use App\Models\CardStatusLog;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlanChange;
use App\Models\User;
use App\Support\Customers\TimelineEvent;
use App\Support\Format;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * History of a holder (US-4.8): sign-up, plan changes and re-enrollment, the
 * status changes of every card the holder ever had, issued invoices,
 * payments and refunds, and notes — most recent first.
 */
class CustomerTimeline
{
    public const string ENROLLMENT = 'enrollment';

    public const string REENROLLMENT = 'reenrollment';

    public const string PLAN_CHANGE = 'plan_change';

    public const string CARD_LINKED = 'card_linked';

    public const string CARD_REPLACED = 'card_replaced';

    public const string CARD_SUSPENDED = 'card_suspended';

    public const string CARD_REACTIVATED = 'card_reactivated';

    public const string CARD_CANCELLED = 'card_cancelled';

    public const string CARD_STATUS = 'card_status';

    public const string INVOICE_ISSUED = 'invoice_issued';

    public const string PAYMENT = 'payment';

    public const string REFUND = 'refund';

    public const string NOTE = 'note';

    /**
     * @return Collection<int, TimelineEvent>
     */
    public function for(Customer $customer): Collection
    {
        $events = collect([
            ...$this->planChangeEvents($customer),
            ...$this->cardEvents($customer),
            ...$this->invoiceEvents($customer),
            ...$this->paymentEvents($customer),
            ...$this->noteEvents($customer),
        ]);

        return $events
            ->keys()
            ->sort(fn (int $a, int $b): int => [$events[$b]->date, $b] <=> [$events[$a]->date, $a])
            ->map(fn (int $key): TimelineEvent => $events[$key])
            ->values();
    }

    /**
     * @return array<int, TimelineEvent>
     */
    protected function planChangeEvents(Customer $customer): array
    {
        return $customer->planChanges()
            ->with(['fromPlan:id,name', 'toPlan:id,name', 'user:id,name'])
            ->oldest()->oldest('id')
            ->get()
            ->map(function (PlanChange $change): TimelineEvent {
                $toPrice = Format::money($change->to_price);

                return match (true) {
                    $change->from_plan_id === null => $this->event($change->created_at, self::ENROLLMENT,
                        "Adesão ao plano {$change->toPlan->name}", "{$toPrice}/mês", $change->user),
                    $change->reason === 'Reingresso' => $this->event($change->created_at, self::REENROLLMENT,
                        "Reingresso no plano {$change->toPlan->name}", "{$toPrice}/mês", $change->user),
                    default => $this->event($change->created_at, self::PLAN_CHANGE, 'Troca de plano', collect([
                        "{$change->fromPlan?->name} (".Format::money((string) $change->from_price).") → {$change->toPlan->name} ({$toPrice})",
                        $change->reason,
                    ])->filter()->implode(' · '), $change->user),
                };
            })
            ->all();
    }

    /**
     * Status changes of every card of the holder; the stock entry is not part of the history.
     *
     * @return array<int, TimelineEvent>
     */
    protected function cardEvents(Customer $customer): array
    {
        $cards = $customer->cards()->orderBy('assigned_at')->orderBy('id')->get();

        return CardStatusLog::query()
            ->whereIn('card_id', $cards->modelKeys())
            ->where('to_card_status_id', '!=', CardStatus::InStock->id())
            ->with('user:id,name')
            ->oldest()->oldest('id')
            ->get()
            ->map(fn (CardStatusLog $log): TimelineEvent => $this->cardEvent($log, $cards->find($log->card_id), $cards))
            ->all();
    }

    /**
     * @param  Collection<int, Card>  $cards
     */
    protected function cardEvent(CardStatusLog $log, Card $card, Collection $cards): TimelineEvent
    {
        $from = $log->from_card_status_id ? CardStatus::fromId($log->from_card_status_id) : null;
        $to = CardStatus::fromId($log->to_card_status_id);

        [$type, $title, $description] = match (true) {
            $from === CardStatus::InStock => [self::CARD_LINKED, "Cartão {$card->number} vinculado", $log->reason],
            in_array($to, [CardStatus::Lost, CardStatus::Discarded], true) => [
                self::CARD_REPLACED,
                'Segunda via do cartão',
                collect([$this->replacementLabel($log, $card, $cards), $to->label(), $log->reason])->filter()->implode(' · '),
            ],
            $to === CardStatus::Suspended => [self::CARD_SUSPENDED, "Cartão {$card->number} suspenso", $log->reason],
            $to === CardStatus::Active => [self::CARD_REACTIVATED, "Cartão {$card->number} reativado", $log->reason],
            $to === CardStatus::Cancelled => [self::CARD_CANCELLED, "Cartão {$card->number} cancelado", $log->reason],
            default => [self::CARD_STATUS, "Cartão {$card->number}: {$to->label()}", $log->reason],
        };

        return $this->event($log->created_at, $type, $title, $description, $log->user);
    }

    /**
     * "000087 → 000123": the old card and the card linked on or after the replacement.
     *
     * @param  Collection<int, Card>  $cards
     */
    protected function replacementLabel(CardStatusLog $log, Card $card, Collection $cards): string
    {
        $replacement = $cards->first(fn (Card $other): bool => $other->id !== $card->id
            && $other->assigned_at !== null
            && $other->assigned_at->gte($log->created_at->copy()->startOfDay()));

        return $replacement ? "{$card->number} → {$replacement->number}" : $card->number;
    }

    /**
     * @return array<int, TimelineEvent>
     */
    protected function invoiceEvents(Customer $customer): array
    {
        return $customer->invoices()
            ->oldest()->oldest('id')
            ->get()
            ->map(fn (Invoice $invoice): TimelineEvent => $this->event(
                $invoice->created_at,
                self::INVOICE_ISSUED,
                'Mensalidade '.$invoice->reference_month->format('m/Y').' gerada',
                Format::money($invoice->amount).' · vence em '.Format::date($invoice->due_date),
                null,
            ))
            ->all();
    }

    /**
     * Payments, plus a separate event for each refund.
     *
     * @return array<int, TimelineEvent>
     */
    protected function paymentEvents(Customer $customer): array
    {
        return $customer->payments()
            ->with(['invoice:id,reference_month', 'user:id,name', 'refundedBy:id,name'])
            ->oldest('payments.created_at')->oldest('payments.id')
            ->get()
            ->flatMap(function (Payment $payment): array {
                $receipt = "Recibo nº {$payment->receipt_number}";
                $amount = Format::money($payment->amount);
                $reference = 'ref. '.$payment->invoice->reference_month->format('m/Y');

                $events = [$this->event($payment->created_at, self::PAYMENT, 'Pagamento recebido',
                    implode(' · ', [$receipt, PaymentMethod::fromId($payment->payment_method_id)->label(), $amount, $reference]), $payment->user)];

                if ($payment->isRefunded()) {
                    $events[] = $this->event($payment->refunded_at, self::REFUND, 'Pagamento estornado',
                        collect([$receipt, $amount, $reference, $payment->refund_reason])->filter()->implode(' · '), $payment->refundedBy);
                }

                return $events;
            })
            ->all();
    }

    /**
     * @return array<int, TimelineEvent>
     */
    protected function noteEvents(Customer $customer): array
    {
        return $customer->notes()
            ->with('user:id,name')
            ->get()
            ->map(fn (CustomerNote $note): TimelineEvent => $this->event($note->created_at, self::NOTE, 'Observação', $note->body, $note->user))
            ->all();
    }

    protected function event(CarbonInterface $date, string $type, string $title, ?string $description, ?User $user): TimelineEvent
    {
        return new TimelineEvent($date, $type, $title, filled($description) ? $description : null, $user->name ?? TimelineEvent::SYSTEM_USER);
    }
}

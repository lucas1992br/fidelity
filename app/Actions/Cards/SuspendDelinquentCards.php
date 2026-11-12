<?php

namespace App\Actions\Cards;

use App\Enums\CardStatus as CardStatusEnum;
use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use App\Models\Card;
use App\Models\Invoice;
use App\Queries\BoletoInstallments;
use App\Settings\ClinicSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Suspend the active current cards of holders with an overdue invoice, or a
 * pending boleto installment ({@see BoletoInstallments}), late for at least
 * `suspend_after_days` days (inclusive). The change is made by the system
 * (no user) and the reason carries the longest delay.
 */
class SuspendDelinquentCards
{
    public function __construct(
        protected ChangeCardStatus $changeCardStatus,
        protected ClinicSettings $settings,
    ) {}

    /**
     * @return int How many cards were suspended.
     */
    public function handle(): int
    {
        $today = CarbonImmutable::today();
        $cutoff = $today->subDays($this->settings->suspend_after_days);

        $oldestDueDates = Invoice::query()
            ->where('invoice_status_id', InvoiceStatusEnum::Overdue->id())
            ->whereDate('due_date', '<=', $cutoff)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, min(due_date) as oldest_due_date')
            ->pluck('oldest_due_date', 'customer_id');

        BoletoInstallments::byHolder(BoletoInstallments::dueBefore($cutoff, inclusive: true))
            ->get()
            ->each(function (object $installment) use (&$oldestDueDates): void {
                $current = $oldestDueDates[$installment->customer_id] ?? null;

                if ($current === null || $installment->due_date < $current) {
                    $oldestDueDates[$installment->customer_id] = $installment->due_date;
                }
            });

        $suspended = 0;

        Card::query()
            ->current()
            ->withStatus(CardStatusEnum::Active)
            ->whereIn('customer_id', $oldestDueDates->keys())
            ->lazyById()
            ->each(function (Card $card) use ($oldestDueDates, $today, &$suspended): void {
                $daysOverdue = (int) CarbonImmutable::parse($oldestDueDates[$card->customer_id])->diffInDays($today);

                $wasSuspended = DB::transaction(function () use ($card, $daysOverdue): bool {
                    $card = Card::query()->lockForUpdate()->find($card->id);

                    if ($card === null || ! $card->is_current || ! $card->hasStatus(CardStatusEnum::Active)) {
                        return false;
                    }

                    $this->changeCardStatus->handle(
                        $card,
                        CardStatusEnum::Suspended,
                        "Suspensão automática: {$daysOverdue} ".($daysOverdue === 1 ? 'dia' : 'dias').' em atraso',
                    );

                    return true;
                });

                if ($wasSuspended) {
                    $suspended++;
                }
            });

        return $suspended;
    }
}

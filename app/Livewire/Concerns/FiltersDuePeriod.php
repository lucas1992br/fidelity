<?php

namespace App\Livewire\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Livewire\Attributes\Url;

/**
 * Due date period filter of the accounts payable and receivable screens,
 * kept in the URL (`?de=2026-10-01&ate=2026-10-31`): the current month by
 * default, any interval of days or months, or a whole month picked at once.
 * Entries overdue before the period are only listed when asked (`vencidas`).
 */
trait FiltersDuePeriod
{
    #[Url(as: 'de')]
    public string $from = '';

    #[Url(as: 'ate')]
    public string $until = '';

    /**
     * Whether entries overdue before the period start are listed too.
     */
    #[Url(as: 'vencidas')]
    public bool $includeEarlierOverdue = false;

    /**
     * Month typed in the month shortcut ("2026-10"); fills the whole month.
     */
    public string $month = '';

    public function mountFiltersDuePeriod(): void
    {
        $this->normalizePeriod();
    }

    public function updatedFrom(): void
    {
        $this->normalizePeriod();
    }

    public function updatedUntil(): void
    {
        $this->normalizePeriod();
    }

    public function updatedMonth(): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1) {
            $this->showMonth(CarbonImmutable::parse("{$this->month}-01"));
        }
    }

    public function currentMonth(): void
    {
        $this->showMonth(CarbonImmutable::today());
    }

    public function previousMonth(): void
    {
        $this->showMonth($this->periodStart()->subMonthNoOverflow());
    }

    public function nextMonth(): void
    {
        $this->showMonth($this->periodStart()->addMonthNoOverflow());
    }

    public function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->from)->startOfDay();
    }

    public function periodEnd(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->until)->startOfDay();
    }

    /**
     * "01/10/2026 a 31/10/2026", or "Outubro de 2026" for a whole month.
     */
    public function periodLabel(): string
    {
        $start = $this->periodStart();
        $end = $this->periodEnd();

        if ($start->isSameDay($start->startOfMonth()) && $end->isSameDay($start->endOfMonth())) {
            return ucfirst($start->locale('pt_BR')->translatedFormat('F \d\e Y'));
        }

        return $start->format('d/m/Y').' a '.$end->format('d/m/Y');
    }

    /**
     * Keep the rows due in the period (and, when asked, those overdue before it).
     */
    protected function applyDuePeriod(Builder $query, string $column = 'due_date'): Builder
    {
        $start = $this->periodStart()->toDateString();
        $end = $this->periodEnd()->toDateString();
        $today = CarbonImmutable::today()->toDateString();

        return $query->where(function (Builder $query) use ($column, $start, $end, $today): void {
            $query->whereBetween($column, [$start, $end]);

            if ($this->includeEarlierOverdue) {
                $query->orWhere(fn (Builder $query) => $query->where($column, '<', $start)->where($column, '<', $today));
            }
        });
    }

    protected function showMonth(CarbonImmutable $month): void
    {
        $this->from = $month->startOfMonth()->toDateString();
        $this->until = $month->endOfMonth()->toDateString();
        $this->month = $month->format('Y-m');
    }

    /**
     * Fall back to the current month on an invalid date and swap an inverted interval.
     */
    protected function normalizePeriod(): void
    {
        $isDate = fn (string $date): bool => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);

        if (! $isDate($this->from) || ! $isDate($this->until)) {
            $month = $isDate($this->from) ? CarbonImmutable::parse($this->from) : ($isDate($this->until) ? CarbonImmutable::parse($this->until) : CarbonImmutable::today());
            $this->from = $isDate($this->from) ? $this->from : $month->startOfMonth()->toDateString();
            $this->until = $isDate($this->until) ? $this->until : $month->endOfMonth()->toDateString();
        }

        if ($this->from > $this->until) {
            [$this->from, $this->until] = [$this->until, $this->from];
        }

        $this->month = $this->periodStart()->isSameDay($this->periodStart()->startOfMonth()) && $this->periodEnd()->isSameDay($this->periodStart()->endOfMonth())
            ? $this->periodStart()->format('Y-m')
            : '';
    }
}

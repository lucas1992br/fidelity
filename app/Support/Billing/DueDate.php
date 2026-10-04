<?php

namespace App\Support\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Due date calculations for the monthly fees.
 */
class DueDate
{
    /**
     * Next `due_day` strictly after the given date: joining on the 10th with
     * `due_day` 10 falls due on the 10th of the following month.
     */
    public static function next(CarbonInterface $from, int $dueDay): CarbonImmutable
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $dueDate = $from->setDay($dueDay);

        return $dueDate->gt($from) ? $dueDate : $from->startOfMonth()->addMonthNoOverflow()->setDay($dueDay);
    }
}

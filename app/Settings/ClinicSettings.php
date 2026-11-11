<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Clinic data and the program parameters (group "clinic").
 */
class ClinicSettings extends Settings
{
    public string $clinic_name;

    public ?string $clinic_cnpj;

    public ?string $clinic_address;

    public ?string $clinic_phone;

    /**
     * Default due day (1–28) suggested on new sign-ups.
     */
    public int $default_due_day;

    /**
     * Days an invoice may stay overdue before the card is suspended.
     */
    public int $suspend_after_days;

    /**
     * Day of the month the next month's invoices are generated.
     */
    public int $invoice_generation_day;

    /**
     * Stock level below which the low card stock alert is shown.
     */
    public int $min_card_stock;

    /**
     * Optional regular expression the printed card number must match.
     */
    public ?string $card_number_pattern;

    /**
     * Percentage (0–100) of the enrollment fee paid to the seller as commission.
     */
    public float $enrollment_commission_percent;

    /**
     * Days until the acquirer deposits an anticipated card sale (D+N).
     */
    public int $card_settlement_days;

    public static function group(): string
    {
        return 'clinic';
    }
}

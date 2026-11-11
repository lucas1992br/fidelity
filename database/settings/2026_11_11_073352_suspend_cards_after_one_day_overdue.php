<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Suspend the card on the first day after an unpaid due date, so the
     * holder must settle the payment to use the card again.
     */
    public function up(): void
    {
        $this->migrator->update('clinic.suspend_after_days', fn (): int => 1);
    }

    /**
     * Restore the previous 30-day tolerance.
     */
    public function down(): void
    {
        $this->migrator->update('clinic.suspend_after_days', fn (): int => 30);
    }
};

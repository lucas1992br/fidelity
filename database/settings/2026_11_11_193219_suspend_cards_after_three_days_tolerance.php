<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Three days of tolerance before suspending: the card is suspended on the
     * 4th day late, giving time for the boleto clearing and the card statement
     * import. E.g. due on the 10th, suspended on the 14th.
     */
    public function up(): void
    {
        $this->migrator->update('clinic.suspend_after_days', fn (): int => 4);
    }

    /**
     * Restore the suspension on the first day late.
     */
    public function down(): void
    {
        $this->migrator->update('clinic.suspend_after_days', fn (): int => 1);
    }
};

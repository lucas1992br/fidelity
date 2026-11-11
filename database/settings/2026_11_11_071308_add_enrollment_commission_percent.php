<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Percentage of the enrollment fee paid to the seller as commission;
     * zero until the admin sets it.
     */
    public function up(): void
    {
        $this->migrator->add('clinic.enrollment_commission_percent', 0.0);
    }
};

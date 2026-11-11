<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Days until the acquirer deposits an anticipated card sale (D+1 by default).
     */
    public function up(): void
    {
        $this->migrator->add('clinic.card_settlement_days', 1);
    }
};

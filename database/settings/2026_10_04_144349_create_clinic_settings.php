<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Initial clinic data and program parameters; the clinic name is a
     * placeholder until the real data is filled in by the admin.
     */
    public function up(): void
    {
        $this->migrator->add('clinic.clinic_name', 'Policlínica');
        $this->migrator->add('clinic.clinic_cnpj', null);
        $this->migrator->add('clinic.clinic_address', null);
        $this->migrator->add('clinic.clinic_phone', null);
        $this->migrator->add('clinic.default_due_day', 10);
        $this->migrator->add('clinic.suspend_after_days', 30);
        $this->migrator->add('clinic.invoice_generation_day', 20);
        $this->migrator->add('clinic.min_card_stock', 50);
        $this->migrator->add('clinic.card_number_pattern', null);
    }
};

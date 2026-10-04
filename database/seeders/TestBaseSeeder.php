<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TestBaseSeeder extends Seeder
{
    /**
     * Data every feature test relies on: lookups, roles and permissions and
     * the financial categories (including the "Mensalidades" system one).
     */
    public function run(): void
    {
        $this->call([
            LookupSeeder::class,
            RolesAndPermissionsSeeder::class,
            FinancialCategorySeeder::class,
        ]);
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database; the demo data only in local.
     */
    public function run(): void
    {
        $this->call([
            LookupSeeder::class,
            PlanSeeder::class,
            FinancialCategorySeeder::class,
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
        ]);

        if (app()->isLocal()) {
            $this->call(DemoSeeder::class);
        }
    }
}

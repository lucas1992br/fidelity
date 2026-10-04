<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    /**
     * Create the initial plans (spec §13) when missing, matched by name.
     * Existing plans are left untouched, since the admin may edit them.
     */
    public function run(): void
    {
        $now = now();

        foreach ($this->plans() as $plan) {
            if (DB::table('plans')->where('name', $plan['name'])->exists()) {
                continue;
            }

            DB::table('plans')->insert([...$plan, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * Plans and prices from the design prototype; "Familiar Plus" is not seeded.
     *
     * @return list<array{name: string, price: string, max_dependents: int}>
     */
    protected function plans(): array
    {
        return [
            ['name' => 'Individual', 'price' => '49.90', 'max_dependents' => 0],
            ['name' => 'Familiar', 'price' => '89.90', 'max_dependents' => 4],
        ];
    }
}

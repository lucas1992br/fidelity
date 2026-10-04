<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FinancialCategorySeeder extends Seeder
{
    /**
     * Create the initial financial categories when missing, matched by name
     * and entry type. The entry type id is resolved by slug, never hard-coded.
     */
    public function run(): void
    {
        $now = now();
        $entryTypeIds = DB::table('entry_types')->pluck('id', 'slug');

        foreach ($this->categories() as $category) {
            $entryTypeId = $entryTypeIds[$category['entry_type']];

            $exists = DB::table('financial_categories')
                ->where('name', $category['name'])
                ->where('entry_type_id', $entryTypeId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('financial_categories')->insert([
                'entry_type_id' => $entryTypeId,
                'name' => $category['name'],
                'is_system' => $category['is_system'] ?? false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Categories from the "Seeds de domínio" section of the schema.
     *
     * @return list<array{name: string, entry_type: string, is_system?: bool}>
     */
    protected function categories(): array
    {
        return [
            ['name' => 'Mensalidades', 'entry_type' => 'income', 'is_system' => true],
            ['name' => 'Outras receitas', 'entry_type' => 'income'],
            ['name' => 'Aluguel', 'entry_type' => 'expense'],
            ['name' => 'Salários', 'entry_type' => 'expense'],
            ['name' => 'Materiais', 'entry_type' => 'expense'],
            ['name' => 'Marketing', 'entry_type' => 'expense'],
            ['name' => 'Impostos', 'entry_type' => 'expense'],
            ['name' => 'Outras despesas', 'entry_type' => 'expense'],
        ];
    }
}

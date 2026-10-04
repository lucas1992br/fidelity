<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LookupSeeder extends Seeder
{
    /**
     * Upsert the lookup values by slug, so ids are never hard-coded and
     * running the seeder again neither duplicates rows nor changes ids.
     */
    public function run(): void
    {
        foreach ($this->lookups() as $table => $rows) {
            $this->upsertBySlug($table, $rows);
        }
    }

    /**
     * Lookup values from the "Lookup Table Seeds" section of the schema.
     *
     * @return array<string, list<array<string, string|bool>>>
     */
    protected function lookups(): array
    {
        return [
            'card_statuses' => [
                ['slug' => 'in_stock', 'name' => 'Em estoque', 'color' => 'blue', 'is_current' => false, 'is_reusable' => true],
                ['slug' => 'active', 'name' => 'Ativo', 'color' => 'green', 'is_current' => true, 'is_reusable' => false],
                ['slug' => 'suspended', 'name' => 'Suspenso', 'color' => 'amber', 'is_current' => true, 'is_reusable' => false],
                ['slug' => 'cancelled', 'name' => 'Cancelado', 'color' => 'red', 'is_current' => false, 'is_reusable' => false],
                ['slug' => 'lost', 'name' => 'Extraviado', 'color' => 'gray', 'is_current' => false, 'is_reusable' => false],
                ['slug' => 'discarded', 'name' => 'Inutilizado', 'color' => 'gray', 'is_current' => false, 'is_reusable' => false],
            ],
            'invoice_statuses' => [
                ['slug' => 'open', 'name' => 'Em aberto', 'color' => 'blue', 'is_payable' => true],
                ['slug' => 'overdue', 'name' => 'Em atraso', 'color' => 'red', 'is_payable' => true],
                ['slug' => 'paid', 'name' => 'Pago', 'color' => 'green', 'is_payable' => false],
                ['slug' => 'cancelled', 'name' => 'Cancelada', 'color' => 'gray', 'is_payable' => false],
            ],
            'payment_methods' => [
                ['slug' => 'pix', 'name' => 'PIX'],
                ['slug' => 'cash', 'name' => 'Dinheiro'],
                ['slug' => 'card', 'name' => 'Cartão'],
                ['slug' => 'boleto', 'name' => 'Boleto'],
            ],
            'kinships' => [
                ['slug' => 'spouse', 'name' => 'Cônjuge'],
                ['slug' => 'child', 'name' => 'Filho(a)'],
                ['slug' => 'father', 'name' => 'Pai'],
                ['slug' => 'mother', 'name' => 'Mãe'],
                ['slug' => 'sibling', 'name' => 'Irmão(ã)'],
                ['slug' => 'other', 'name' => 'Outro'],
            ],
            'entry_types' => [
                ['slug' => 'income', 'name' => 'Receita'],
                ['slug' => 'expense', 'name' => 'Despesa'],
            ],
            'entry_statuses' => [
                ['slug' => 'pending', 'name' => 'Pendente', 'color' => 'blue'],
                ['slug' => 'paid', 'name' => 'Pago/Recebido', 'color' => 'green'],
                ['slug' => 'cancelled', 'name' => 'Cancelado', 'color' => 'gray'],
            ],
        ];
    }

    /**
     * @param  list<array<string, string|bool>>  $rows
     */
    protected function upsertBySlug(string $table, array $rows): void
    {
        $now = now();

        $rows = array_map(fn (array $row): array => [...$row, 'created_at' => $now, 'updated_at' => $now], $rows);

        $updateColumns = array_values(array_diff(array_keys($rows[0]), ['slug', 'created_at']));

        DB::table($table)->upsert($rows, ['slug'], $updateColumns);
    }
}

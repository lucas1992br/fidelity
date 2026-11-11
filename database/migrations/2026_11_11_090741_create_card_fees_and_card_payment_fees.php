<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Card machine fees: one rate per modality (debit, credit at once and
     * credit in 2 to 12 installments, the same for every brand), starting at
     * zero. A card payment freezes its rate and fee amount; its fee becomes an
     * expense in the "Taxas da maquininha" system category, linked to the
     * acquirer's income entry, so both are settled together.
     */
    public function up(): void
    {
        Schema::create('card_fees', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_debit')->default(false);
            $table->unsignedSmallInteger('installments');
            $table->decimal('rate', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['is_debit', 'installments']);
        });

        DB::statement('ALTER TABLE card_fees ADD CONSTRAINT card_fees_rate_range CHECK (rate BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE card_fees ADD CONSTRAINT card_fees_installments_range CHECK (installments BETWEEN 1 AND 12 AND (NOT is_debit OR installments = 1))');

        $now = now();
        DB::table('card_fees')->insert([
            ['is_debit' => true, 'installments' => 1, 'rate' => 0, 'created_at' => $now, 'updated_at' => $now],
            ...array_map(fn (int $installments): array => ['is_debit' => false, 'installments' => $installments, 'rate' => 0, 'created_at' => $now, 'updated_at' => $now], range(1, 12)),
        ]);

        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('is_debit')->default(false)->after('installments');
            $table->decimal('card_fee_rate', 5, 2)->nullable()->after('card_last4');
            $table->decimal('card_fee_amount', 12, 2)->nullable()->after('card_fee_rate');
        });

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->foreignId('related_entry_id')->nullable()->after('installment_number')->constrained('financial_entries')->restrictOnDelete();
        });

        $expenseTypeId = DB::table('entry_types')->where('slug', 'expense')->value('id');

        if ($expenseTypeId !== null && DB::table('financial_categories')->where('name', 'Taxas da maquininha')->where('entry_type_id', $expenseTypeId)->doesntExist()) {
            DB::table('financial_categories')->insert([
                'entry_type_id' => $expenseTypeId,
                'name' => 'Taxas da maquininha',
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('financial_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('related_entry_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['is_debit', 'card_fee_rate', 'card_fee_amount']);
        });

        Schema::dropIfExists('card_fees');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow paying an invoice in up to 12 installments: the payment stores the
     * number of installments and each one becomes an income entry, so a
     * payment may now have several entries, numbered from 1.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedSmallInteger('installments')->default(1)->after('payment_method_id');
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_installments_range CHECK (installments BETWEEN 1 AND 12)');

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('installment_number')->nullable()->after('payment_id');
        });

        DB::table('financial_entries')->whereNotNull('payment_id')->update(['installment_number' => 1]);

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->dropUnique(['payment_id']);
            $table->unique(['payment_id', 'installment_number']);
        });

        DB::statement('ALTER TABLE financial_entries ADD CONSTRAINT financial_entries_payment_installment_required CHECK (payment_id IS NULL OR (installment_number IS NOT NULL AND installment_number BETWEEN 1 AND 12))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE financial_entries DROP CONSTRAINT IF EXISTS financial_entries_payment_installment_required');

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->dropUnique(['payment_id', 'installment_number']);
            $table->dropColumn('installment_number');
            $table->unique('payment_id');
        });

        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_installments_range');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('installments');
        });
    }
};

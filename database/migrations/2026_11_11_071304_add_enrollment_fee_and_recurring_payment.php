<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the plan's enrollment fee, the fee and commission percentage
     * charged at each sale, the "Cartão recorrente" payment method and the
     * "Taxas de adesão" system income category. The lookup rows are only
     * inserted on an already seeded database; a fresh one gets them from the
     * seeders.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('enrollment_fee', 12, 2)->default(0)->after('price');
        });

        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_enrollment_fee_non_negative CHECK (enrollment_fee >= 0)');

        Schema::table('plan_changes', function (Blueprint $table) {
            $table->decimal('enrollment_fee', 12, 2)->nullable()->after('to_price');
            $table->decimal('commission_percent', 5, 2)->nullable()->after('enrollment_fee');
        });

        $now = now();

        if (DB::table('payment_methods')->exists()) {
            DB::table('payment_methods')->insertOrIgnore([
                'slug' => 'recurring',
                'name' => 'Cartão recorrente',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $incomeTypeId = DB::table('entry_types')->where('slug', 'income')->value('id');

        if ($incomeTypeId !== null && DB::table('financial_categories')->where('name', 'Taxas de adesão')->where('entry_type_id', $incomeTypeId)->doesntExist()) {
            DB::table('financial_categories')->insert([
                'entry_type_id' => $incomeTypeId,
                'name' => 'Taxas de adesão',
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
        Schema::table('plan_changes', function (Blueprint $table) {
            $table->dropColumn(['enrollment_fee', 'commission_percent']);
        });

        DB::statement('ALTER TABLE plans DROP CONSTRAINT IF EXISTS plans_enrollment_fee_non_negative');

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('enrollment_fee');
        });
    }
};

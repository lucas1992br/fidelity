<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company cards: a company groups employees (holders with their own card
     * and dependents) and pays one consolidated bill per month. Every holder
     * gets its own monthly amount, typed at the sale instead of the plan price
     * (backfilled from the current plan price). Invoices freeze the holder's
     * company on issuance and a company payment records the company.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->char('cnpj', 14)->unique();
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone');
            $table->smallInteger('due_day');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_due_day_range CHECK (due_day BETWEEN 1 AND 28)');
        DB::statement('CREATE INDEX companies_name_trgm ON companies USING gin (f_unaccent(name) gin_trgm_ops)');

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('plan_id')->constrained()->restrictOnDelete();
            $table->decimal('monthly_amount', 12, 2)->nullable()->after('company_id');
        });

        DB::statement('UPDATE customers SET monthly_amount = plans.price FROM plans WHERE plans.id = customers.plan_id');
        DB::statement('ALTER TABLE customers ALTER COLUMN monthly_amount SET NOT NULL');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_monthly_amount_non_negative CHECK (monthly_amount >= 0)');

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('customer_id')->constrained()->restrictOnDelete();
            $table->index(['company_id', 'reference_month']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('invoice_id')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'reference_month']);
            $table->dropConstrainedForeignId('company_id');
        });

        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_monthly_amount_non_negative');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('monthly_amount');
            $table->dropConstrainedForeignId('company_id');
        });

        DB::statement('DROP INDEX IF EXISTS companies_name_trgm');
        Schema::dropIfExists('companies');
    }
};

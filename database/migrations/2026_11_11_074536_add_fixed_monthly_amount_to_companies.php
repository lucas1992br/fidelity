<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Companies pay a fixed monthly amount instead of the sum of their
     * employees. The amount starts as the current sum of the employees in
     * force, and each employee's own monthly amount becomes zero (their
     * invoices only track the card). Each reference month of a company bill
     * freezes the fixed amount in `company_bills`; months already invoiced
     * get a zero row, so their bill stays the sum of the invoices issued.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('monthly_amount', 12, 2)->default(0)->after('due_day');
        });

        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_monthly_amount_non_negative CHECK (monthly_amount >= 0)');

        DB::statement(<<<'SQL'
            UPDATE companies SET monthly_amount = totals.amount
            FROM (
                SELECT customers.company_id, sum(customers.monthly_amount) AS amount
                FROM customers
                WHERE customers.company_id IS NOT NULL
                  AND customers.deleted_at IS NULL
                  AND EXISTS (SELECT 1 FROM cards WHERE cards.customer_id = customers.id AND cards.is_current)
                GROUP BY customers.company_id
            ) AS totals
            WHERE totals.company_id = companies.id
            SQL);

        DB::statement('UPDATE customers SET monthly_amount = 0 WHERE company_id IS NOT NULL');

        Schema::create('company_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('reference_month');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['company_id', 'reference_month']);
        });

        DB::statement('ALTER TABLE company_bills ADD CONSTRAINT company_bills_amount_non_negative CHECK (amount >= 0)');

        DB::statement(<<<'SQL'
            INSERT INTO company_bills (company_id, reference_month, amount, created_at, updated_at)
            SELECT DISTINCT company_id, reference_month, 0, now(), now()
            FROM invoices
            WHERE company_id IS NOT NULL
            SQL);
    }

    /**
     * Reverse the migrations. The employees' former monthly amounts are not restored.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_bills');

        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_monthly_amount_non_negative');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('monthly_amount');
        });
    }
};

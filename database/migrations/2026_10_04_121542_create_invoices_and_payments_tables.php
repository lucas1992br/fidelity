<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the monthly invoices and their payments; receipt numbers come
     * from a sequence and each invoice has at most one non-refunded payment.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->date('reference_month');
            $table->decimal('amount', 12, 2);
            $table->date('due_date');
            $table->foreignId('invoice_status_id')->constrained()->restrictOnDelete();
            $table->date('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'reference_month']);
            $table->index(['invoice_status_id', 'due_date']);
        });

        DB::statement('CREATE SEQUENCE receipt_number_seq');

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->bigInteger('receipt_number')->unique()->default(DB::raw("nextval('receipt_number_seq')"));
            $table->date('paid_at');
            $table->decimal('amount', 12, 2);
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('refund_reason')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER SEQUENCE receipt_number_seq OWNED BY payments.receipt_number');
        DB::statement('CREATE UNIQUE INDEX payments_one_active_per_invoice ON payments (invoice_id) WHERE refunded_at IS NULL');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_one_active_per_invoice');
        Schema::dropIfExists('payments');
        DB::statement('DROP SEQUENCE IF EXISTS receipt_number_seq');
        Schema::dropIfExists('invoices');
    }
};

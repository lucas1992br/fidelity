<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow one payment to cover up to 12 consecutive months: the payment
     * stores how many months it covers and `invoice_payment` links it to every
     * covered invoice (the first one stays in `payments.invoice_id`).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedSmallInteger('months')->default(1)->after('amount');
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_months_range CHECK (months BETWEEN 1 AND 12)');

        Schema::create('invoice_payment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['invoice_id', 'payment_id']);
            $table->index('payment_id');
        });

        DB::statement('INSERT INTO invoice_payment (invoice_id, payment_id, created_at, updated_at) SELECT invoice_id, id, created_at, updated_at FROM payments');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_payment');

        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_months_range');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('months');
        });
    }
};

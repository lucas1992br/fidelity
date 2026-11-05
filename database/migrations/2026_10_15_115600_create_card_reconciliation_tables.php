<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Card reconciliation with the acquirer statement (Granito). Only the
     * first 6 and last 4 digits of a payer's credit card are ever stored
     * (never the full number, PCI-DSS): on the payment, on the payer's
     * remembered cards (a holder or a company) and on each imported
     * transaction, which is unique by the acquirer id and links to the
     * payment it settled.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->char('card_bin', 6)->nullable()->after('installments');
            $table->char('card_last4', 4)->nullable()->after('card_bin');
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_card_digits_together CHECK ((card_bin IS NULL) = (card_last4 IS NULL))');

        Schema::create('payment_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('card_bin', 6);
            $table->char('card_last4', 4);
            $table->string('brand')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'card_bin', 'card_last4']);
            $table->unique(['company_id', 'card_bin', 'card_last4']);
            $table->index(['card_bin', 'card_last4']);
        });

        DB::statement('ALTER TABLE payment_cards ADD CONSTRAINT payment_cards_one_owner CHECK ((customer_id IS NULL) <> (company_id IS NULL))');

        Schema::create('card_imports', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');
            $table->unsignedInteger('transactions_count')->default(0);
            $table->unsignedInteger('duplicates_count')->default(0);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_import_id')->constrained()->restrictOnDelete();
            $table->string('external_id')->unique();
            $table->timestamp('occurred_at');
            $table->string('type');
            $table->unsignedSmallInteger('installments')->default(1);
            $table->string('brand')->nullable();
            $table->char('card_bin', 6);
            $table->char('card_last4', 4);
            $table->string('authorization_code')->nullable();
            $table->string('transaction_code')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status');
            $table->string('result')->index();
            $table->string('message')->nullable();
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index(['card_bin', 'card_last4']);
        });

        DB::statement("ALTER TABLE card_transactions ADD CONSTRAINT card_transactions_result_values CHECK (result IN ('paid', 'pending', 'ignored'))");
        DB::statement('ALTER TABLE card_transactions ADD CONSTRAINT card_transactions_installments_range CHECK (installments >= 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_transactions');
        Schema::dropIfExists('card_imports');
        Schema::dropIfExists('payment_cards');

        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_card_digits_together');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['card_bin', 'card_last4']);
        });
    }
};

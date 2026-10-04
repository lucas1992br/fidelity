<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the card stock batches, the cards (at most one current card per
     * customer) and the immutable card status log.
     */
    public function up(): void
    {
        Schema::create('card_batches', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->string('supplier')->nullable();
            $table->date('received_at');
            $table->integer('quantity');
            $table->string('first_number')->nullable();
            $table->string('last_number')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('number')->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('card_status_id')->index()->constrained()->restrictOnDelete();
            $table->boolean('is_current')->default(false);
            $table->date('assigned_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX cards_one_current_per_customer ON cards (customer_id) WHERE is_current');

        Schema::create('card_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_card_status_id')->nullable()->constrained('card_statuses')->restrictOnDelete();
            $table->foreignId('to_card_status_id')->constrained('card_statuses')->restrictOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_status_logs');
        DB::statement('DROP INDEX IF EXISTS cards_one_current_per_customer');
        Schema::dropIfExists('cards');
        Schema::dropIfExists('card_batches');
    }
};

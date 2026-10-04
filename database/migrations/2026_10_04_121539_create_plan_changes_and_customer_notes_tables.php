<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the immutable plan change log and the customer notes.
     */
    public function up(): void
    {
        Schema::create('plan_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_plan_id')->nullable()->constrained('plans')->restrictOnDelete();
            $table->foreignId('to_plan_id')->constrained('plans')->restrictOnDelete();
            $table->decimal('from_price', 12, 2)->nullable();
            $table->decimal('to_price', 12, 2);
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('plan_changes');
    }
};

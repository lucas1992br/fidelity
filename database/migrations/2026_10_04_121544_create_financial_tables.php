<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the financial categories and entries; a composite foreign key
     * keeps each entry's type equal to its category's type.
     */
    public function up(): void
    {
        Schema::create('financial_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_type_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'entry_type_id'], 'financial_categories_id_entry_type_unique');
        });

        Schema::create('financial_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_category_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->date('due_date');
            $table->foreignId('entry_status_id')->constrained()->restrictOnDelete();
            $table->date('paid_at')->nullable()->index();
            $table->foreignId('payment_method_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('supplier_or_payer')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entry_type_id', 'entry_status_id', 'due_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE financial_entries
            ADD CONSTRAINT financial_entries_category_entry_type_foreign
            FOREIGN KEY (financial_category_id, entry_type_id)
            REFERENCES financial_categories (id, entry_type_id)
            ON UPDATE RESTRICT ON DELETE RESTRICT
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_entries');
        Schema::dropIfExists('financial_categories');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the customers (titulares) table, its due day range check and
     * the trigram index for accent and case insensitive name search.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->char('cpf', 11)->unique();
            $table->date('birth_date');
            $table->string('email')->nullable();
            $table->string('phone');
            $table->string('phone_secondary')->nullable();
            $table->char('zip_code', 8);
            $table->string('street');
            $table->string('number');
            $table->string('complement')->nullable();
            $table->string('district');
            $table->string('city');
            $table->char('state', 2);
            $table->smallInteger('due_day');
            $table->date('joined_at');
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_due_day_range CHECK (due_day BETWEEN 1 AND 28)');
        DB::statement('CREATE INDEX customers_name_trgm ON customers USING gin (f_unaccent(name) gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_name_trgm');
        Schema::dropIfExists('customers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional consumer migration template for FinancialRecord.
 * Copy and adapt in your application; the package does not load migrations.
 * Nullable values require availability metadata to retain their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_records', function (Blueprint $table) {
            $table->id();
            $table->string('aun', 9);
            $table->string('district_name')->nullable();
            $table->string('county')->nullable();
            $table->string('fiscal_year', 9);
            $table->string('category', 50);
            $table->string('account_code', 10);
            $table->string('account_name')->nullable();
            $table->string('parent_code', 10)->nullable();
            $table->decimal('budget', 18, 4)->nullable();
            $table->decimal('actual', 18, 4)->nullable();
            $table->json('availability')->nullable()->comment('Property and metric availability; populated by your importer');
            $table->timestamps();

            $table->index(['aun', 'fiscal_year']);
            $table->unique(['aun', 'fiscal_year', 'category', 'account_code'], 'financial_records_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_records');
    }
};

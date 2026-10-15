<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional consumer migration template for FundBalanceRecord.
 * Copy and adapt in your application; the package does not load migrations.
 * Nullable values require availability metadata to retain their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_balance_records', function (Blueprint $table) {
            $table->id();
            $table->string('aun', 9);
            $table->string('district_name')->nullable();
            $table->string('county')->nullable();
            $table->string('fiscal_year', 9);
            $table->decimal('committed', 18, 4)->nullable();
            $table->decimal('assigned', 18, 4)->nullable();
            $table->decimal('unassigned', 18, 4)->nullable();
            $table->json('availability')->nullable()->comment('Property and metric availability; populated by your importer');
            $table->json('provenance')->nullable()->comment('Public PDE citations and field references');
            $table->timestamps();

            $table->index(['aun', 'fiscal_year']);
            $table->unique(['aun', 'fiscal_year'], 'fund_balance_records_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_balance_records');
    }
};

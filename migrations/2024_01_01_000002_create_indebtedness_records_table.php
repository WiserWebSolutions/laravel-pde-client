<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional consumer migration template for IndebtednessRecord.
 * Copy and adapt in your application; the package does not load migrations.
 * Nullable values require availability metadata to retain their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indebtedness_records', function (Blueprint $table) {
            $table->id();
            $table->string('aun', 9);
            $table->string('district_name')->nullable();
            $table->string('county')->nullable();
            $table->string('fiscal_year', 9);
            $table->string('fund_type', 20);
            $table->string('phase', 20);
            $table->decimal('total', 18, 4)->nullable();
            $table->json('categories')->nullable();
            $table->json('availability')->nullable()->comment('Property and metric availability; populated by your importer');
            $table->json('provenance')->nullable()->comment('Public PDE citations and field references');
            $table->timestamps();

            $table->index(['aun', 'fiscal_year']);
            $table->unique(['aun', 'fiscal_year', 'fund_type', 'phase'], 'indebtedness_records_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indebtedness_records');
    }
};

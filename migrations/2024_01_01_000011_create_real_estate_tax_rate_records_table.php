<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional consumer migration template for RealEstateTaxRateRecord.
 * Copy and adapt in your application; the package does not load migrations.
 * Nullable values require availability metadata to retain their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('real_estate_tax_rate_records', function (Blueprint $table) {
            $table->id();
            $table->string('aun', 9);
            $table->string('district_name')->nullable();
            $table->string('school_year', 9);
            $table->string('county')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('mills', 18, 4)->nullable();
            $table->decimal('community_college_mills', 18, 4)->nullable();
            $table->json('availability')->nullable()->comment('Property and metric availability; populated by your importer');
            $table->timestamps();

            $table->index(['aun', 'school_year']);
            $table->unique(['aun', 'school_year', 'county'], 'real_estate_tax_rate_records_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('real_estate_tax_rate_records');
    }
};

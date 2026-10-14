<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional consumer migration template for AdmRecord.
 * Copy and adapt in your application; the package does not load migrations.
 * Nullable values require availability metadata to retain their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adm_records', function (Blueprint $table) {
            $table->id();
            $table->string('aun', 9);
            $table->string('district_name')->nullable();
            $table->string('county')->nullable();
            $table->string('school_year', 9);
            $table->decimal('adm', 18, 4)->nullable();
            $table->decimal('wadm', 18, 4)->nullable();
            $table->decimal('adjusted_adm', 18, 4)->nullable();
            $table->decimal('nonresident_adm', 18, 4)->nullable();
            $table->decimal('total_adm_pde363', 18, 4)->nullable();
            $table->decimal('special_education_adm', 18, 4)->nullable();
            $table->decimal('adjustment_factor', 18, 6)->nullable();
            $table->json('breakdown')->nullable();
            $table->json('availability')->nullable()->comment('Property and metric availability; populated by your importer');
            $table->timestamps();

            $table->index(['aun', 'school_year']);
            $table->unique(['aun', 'school_year'], 'adm_records_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adm_records');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional consumer migration template for PersonnelRecord.
 * Copy and adapt in your application; the package does not load migrations.
 * Nullable values require availability metadata to retain their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_records', function (Blueprint $table) {
            $table->id();
            $table->string('aun', 9);
            $table->string('lea_name')->nullable();
            $table->string('lea_type')->nullable();
            $table->string('county')->nullable();
            $table->string('school_year', 9);
            $table->string('category', 50);
            $table->decimal('count', 18, 4)->nullable();
            $table->decimal('female_count', 18, 4)->nullable();
            $table->decimal('male_count', 18, 4)->nullable();
            $table->decimal('average_salary', 18, 4)->nullable();
            $table->decimal('average_years_service', 18, 4)->nullable();
            $table->decimal('average_years_in_lea', 18, 4)->nullable();
            $table->decimal('average_education_level', 18, 4)->nullable();
            $table->json('availability')->nullable()->comment('Property and metric availability; populated by your importer');
            $table->json('provenance')->nullable()->comment('Public PDE citations and field references');
            $table->timestamps();

            $table->index(['aun', 'school_year']);
            $table->unique(['aun', 'school_year', 'category'], 'personnel_records_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_records');
    }
};

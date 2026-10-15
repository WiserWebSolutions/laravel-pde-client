<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional consumer migration template for GraduationRecord.
 * Copy and adapt in your application; the package does not load migrations.
 * Nullable values require availability metadata to retain their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graduation_records', function (Blueprint $table) {
            $table->id();
            $table->string('aun', 9);
            $table->string('lea_name')->nullable();
            $table->string('lea_type')->nullable();
            $table->string('school_year', 9);
            $table->unsignedTinyInteger('cohort_years');
            $table->string('group', 150);
            $table->decimal('graduates', 18, 4)->nullable();
            $table->decimal('cohort_size', 18, 4)->nullable();
            $table->decimal('rate', 18, 6)->nullable();
            $table->json('availability')->nullable()->comment('Property and metric availability; populated by your importer');
            $table->json('provenance')->nullable()->comment('Public PDE citations and field references');
            $table->timestamps();

            $table->index(['aun', 'school_year']);
            $table->unique(['aun', 'school_year', 'cohort_years', 'group'], 'graduation_records_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graduation_records');
    }
};

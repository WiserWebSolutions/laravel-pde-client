<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional consumer migration template for EnrollmentRecord.
 * Copy and adapt in your application; the package does not load migrations.
 * Nullable values require availability metadata to retain their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_records', function (Blueprint $table) {
            $table->id();
            $table->string('aun', 9);
            $table->string('district_name')->nullable();
            $table->string('county')->nullable();
            $table->string('lea_type')->nullable();
            $table->string('school_year', 9);
            $table->string('grade', 10);
            $table->decimal('count', 18, 4)->nullable();
            $table->json('sub_counts')->nullable();
            $table->decimal('projected_count', 18, 4)->nullable();
            $table->json('projected_sub_counts')->nullable();
            $table->decimal('english_learners_count', 18, 4)->nullable();
            $table->json('english_learners_sub_counts')->nullable();
            $table->json('availability')->nullable()->comment('Property and metric availability; populated by your importer');
            $table->json('provenance')->nullable()->comment('Public PDE citations and field references');
            $table->timestamps();

            $table->index(['aun', 'school_year']);
            $table->unique(['aun', 'school_year', 'grade'], 'enrollment_records_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_records');
    }
};

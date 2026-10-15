<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional consumer migration template for AssessmentRecord.
 * Copy and adapt in your application; the package does not load migrations.
 * Nullable values require availability metadata to retain their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_records', function (Blueprint $table) {
            $table->id();
            $table->string('aun', 9);
            $table->string('district_name')->nullable();
            $table->string('county')->nullable();
            $table->string('school_year', 9);
            $table->string('exam', 20);
            $table->string('subject', 100);
            $table->string('grade', 10);
            $table->string('group', 150);
            $table->decimal('scored', 18, 4)->nullable();
            $table->decimal('percent_advanced', 18, 4)->nullable();
            $table->decimal('percent_proficient', 18, 4)->nullable();
            $table->decimal('percent_basic', 18, 4)->nullable();
            $table->decimal('percent_below_basic', 18, 4)->nullable();
            $table->decimal('percent_proficient_or_above', 18, 4)->nullable();
            $table->json('availability')->nullable()->comment('Property and metric availability; populated by your importer');
            $table->json('provenance')->nullable()->comment('Public PDE citations and field references');
            $table->timestamps();

            $table->index(['aun', 'school_year']);
            $table->unique(['aun', 'school_year', 'exam', 'subject', 'grade', 'group'], 'assessment_records_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_records');
    }
};

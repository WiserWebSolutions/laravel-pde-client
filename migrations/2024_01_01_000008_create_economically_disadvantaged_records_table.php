<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional consumer migration template for EconomicallyDisadvantagedRecord.
 * Copy and adapt in your application; the package does not load migrations.
 * Nullable values require availability metadata to retain their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('economically_disadvantaged_records', function (Blueprint $table) {
            $table->id();
            $table->string('aun', 9);
            $table->string('district_name')->nullable();
            $table->string('lea_type')->nullable();
            $table->string('county')->nullable();
            $table->string('school_year', 9);
            $table->decimal('economically_disadvantaged_count', 18, 4)->nullable();
            $table->decimal('enrollment', 18, 4)->nullable();
            $table->decimal('percent_economically_disadvantaged', 18, 4)->nullable();
            $table->json('availability')->nullable()->comment('Property and metric availability; populated by your importer');
            $table->json('provenance')->nullable()->comment('Public PDE citations and field references');
            $table->timestamps();

            $table->index(['aun', 'school_year']);
            $table->unique(['aun', 'school_year'], 'economically_disadvantaged_records_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('economically_disadvantaged_records');
    }
};

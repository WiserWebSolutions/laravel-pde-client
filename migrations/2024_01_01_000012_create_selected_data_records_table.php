<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional consumer migration template for SelectedDataRecord.
 * Copy and adapt in your application; the package does not load migrations.
 * Nullable values require availability metadata to retain their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('selected_data_records', function (Blueprint $table) {
            $table->id();
            $table->string('aun', 9);
            $table->string('district_name')->nullable();
            $table->string('county')->nullable();
            $table->string('school_year', 9);
            $table->decimal('aid_ratio', 18, 6)->nullable();
            $table->decimal('aid_ratio_rank', 18, 4)->nullable();
            $table->decimal('wadm', 18, 4)->nullable();
            $table->decimal('adm', 18, 4)->nullable();
            $table->decimal('adm_rank', 18, 4)->nullable();
            $table->decimal('equalized_mills', 18, 4)->nullable();
            $table->decimal('equalized_mills_rank', 18, 4)->nullable();
            $table->decimal('population_per_square_mile', 18, 4)->nullable();
            $table->decimal('population_per_square_mile_rank', 18, 4)->nullable();
            $table->decimal('instruction_expense_per_wadm', 18, 4)->nullable();
            $table->decimal('instruction_expense_per_wadm_rank', 18, 4)->nullable();
            $table->decimal('total_expenditure_per_adm', 18, 4)->nullable();
            $table->decimal('total_expenditure_per_adm_rank', 18, 4)->nullable();
            $table->json('availability')->nullable()->comment('Property and metric availability; populated by your importer');
            $table->json('provenance')->nullable()->comment('Public PDE citations and field references');
            $table->timestamps();

            $table->index(['aun', 'school_year']);
            $table->unique(['aun', 'school_year'], 'selected_data_records_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('selected_data_records');
    }
};

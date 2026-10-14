<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional consumer migration template for DropoutRecord.
 * Copy and adapt in your application; the package does not load migrations.
 * Nullable values require availability metadata to retain their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dropout_records', function (Blueprint $table) {
            $table->id();
            $table->string('aun', 9);
            $table->string('lea_name')->nullable();
            $table->string('county')->nullable();
            $table->string('school_year', 9);
            $table->decimal('enrollment', 18, 4)->nullable();
            $table->decimal('male_dropouts', 18, 4)->nullable();
            $table->decimal('female_dropouts', 18, 4)->nullable();
            $table->decimal('dropouts', 18, 4)->nullable();
            $table->decimal('rate', 18, 6)->nullable();
            $table->json('availability')->nullable()->comment('Property and metric availability; populated by your importer');
            $table->timestamps();

            $table->index(['aun', 'school_year']);
            $table->unique(['aun', 'school_year'], 'dropout_records_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dropout_records');
    }
};

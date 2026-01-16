<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('medical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('record_categories')->cascadeOnDelete();

            $table->string('patient_name')->nullable();
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('gender', 20)->nullable();

            $table->longText('chief_complaints')->nullable();
            $table->longText('case_description')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['category_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_records');
    }
};

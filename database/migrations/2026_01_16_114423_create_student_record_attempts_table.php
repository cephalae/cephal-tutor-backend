<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_record_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('medical_record_id')->constrained('medical_records')->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('student_record_assignments')->cascadeOnDelete();

            $table->unsignedTinyInteger('attempt_no');

            $table->json('submitted_codes');
            $table->boolean('is_correct')->default(false);

            $table->json('wrong_codes')->nullable();
            $table->json('missing_codes')->nullable();

            $table->timestamps();

            $table->unique(['assignment_id', 'attempt_no']);
            $table->index(['student_id', 'medical_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_record_attempts');
    }
};

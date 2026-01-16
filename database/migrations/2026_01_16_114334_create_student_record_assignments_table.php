<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_record_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            $table->foreignId('medical_record_id')->constrained('medical_records')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('record_categories')->cascadeOnDelete();

            $table->string('status', 20)->default('assigned'); // assigned|completed|locked
            $table->unsignedTinyInteger('attempts_used')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);

            $table->timestamp('last_attempt_at')->nullable();

            $table->timestamps();

            $table->unique(['student_id', 'medical_record_id']);
            $table->index(['student_id', 'category_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_record_assignments');
    }
};

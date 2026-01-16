<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_category_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('record_categories')->cascadeOnDelete();

            $table->unsignedSmallInteger('questions_count')->default(0);

            $table->timestamps();

            $table->unique(['student_id', 'category_id']);
            $table->index(['category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_category_settings');
    }
};

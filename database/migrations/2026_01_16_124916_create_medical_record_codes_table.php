<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('medical_record_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->constrained('medical_records')->cascadeOnDelete();

            $table->string('code', 30);
            $table->text('description')->nullable();

            $table->longText('comment_wrong')->nullable();
            $table->longText('comment_missing')->nullable();

            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(1);

            $table->timestamps();

            $table->index(['medical_record_id', 'code']);
            $table->unique(['medical_record_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record_codes');
    }
};

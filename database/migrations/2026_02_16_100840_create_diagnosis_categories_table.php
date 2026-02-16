<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosis_categories', function (Blueprint $table) {
            $table->bigIncrements('id'); // import uses explicit IDs

            $table->unsignedBigInteger('parent_id')->nullable()->index();

            $table->string('category_name', 255)->index(); // e.g., A00-B99 / A00-A09 / A00
            $table->text('description')->nullable();
            $table->string('keyword', 255)->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->nullable();

            // Tree helpers (computed at import time)
            $table->unsignedInteger('depth')->default(0)->index();
            $table->text('path')->nullable();       // e.g. "1/2/3"
            $table->text('path_label')->nullable(); // e.g. "A00-B99 > A00-A09 > A00"

            // Optional audit fields from CSV (keep if you want; can remove later)
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->unsignedBigInteger('modified_by')->nullable();
            $table->string('modified_ip', 45)->nullable();

            $table->timestamps();

            $table->foreign('parent_id')
                ->references('id')
                ->on('diagnosis_categories')
                ->nullOnDelete();
        });

        // Optional (recommended) Postgres trigram support for fast ILIKE %...%
        // If your DB user can't create extensions, this will safely fail.
        if (DB::getDriverName() === 'pgsql') {
            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm;');
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnosis_categories');
    }
};

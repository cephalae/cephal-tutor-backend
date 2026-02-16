<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosis_codes', function (Blueprint $table) {
            $table->bigIncrements('id'); // import uses explicit IDs

            $table->unsignedBigInteger('category_id')->index();
            $table->string('code', 50)->index(); // keep as string (can be 1, 1.1, A00, etc.)
            $table->text('long_description')->nullable();
            $table->string('short_description', 255)->nullable();

            $table->timestamps();

            $table->foreign('category_id')
                ->references('id')
                ->on('diagnosis_categories')
                ->cascadeOnDelete();

            // safer than unique(code) if your dataset contains same code in multiple categories
            $table->unique(['category_id', 'code'], 'uniq_diag_code_per_category');
        });

        // Optional trigram indexes for faster ILIKE search (Postgres)
        if (DB::getDriverName() === 'pgsql') {
            try {
                DB::statement("CREATE INDEX IF NOT EXISTS diagnosis_categories_name_trgm
                               ON diagnosis_categories USING gin (category_name gin_trgm_ops);");
                DB::statement("CREATE INDEX IF NOT EXISTS diagnosis_categories_desc_trgm
                               ON diagnosis_categories USING gin (description gin_trgm_ops);");
                DB::statement("CREATE INDEX IF NOT EXISTS diagnosis_categories_pathlabel_trgm
                               ON diagnosis_categories USING gin (path_label gin_trgm_ops);");

                DB::statement("CREATE INDEX IF NOT EXISTS diagnosis_codes_code_trgm
                               ON diagnosis_codes USING gin (code gin_trgm_ops);");
                DB::statement("CREATE INDEX IF NOT EXISTS diagnosis_codes_longdesc_trgm
                               ON diagnosis_codes USING gin (long_description gin_trgm_ops);");
                DB::statement("CREATE INDEX IF NOT EXISTS diagnosis_codes_shortdesc_trgm
                               ON diagnosis_codes USING gin (short_description gin_trgm_ops);");
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnosis_codes');
    }
};

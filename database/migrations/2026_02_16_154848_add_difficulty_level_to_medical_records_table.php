<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            // 1=Level 1, 2=Level 2, 3=Level 3
            $table->unsignedTinyInteger('difficulty_level')
                ->default(1)
                ->after('case_description');

            $table->index('difficulty_level');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropIndex(['difficulty_level']);
            $table->dropColumn('difficulty_level');
        });
    }
};

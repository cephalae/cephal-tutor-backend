<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->string('source_uid', 64)->nullable()->unique()->after('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropUnique(['source_uid']);
            $table->dropColumn('source_uid');
        });
    }
};

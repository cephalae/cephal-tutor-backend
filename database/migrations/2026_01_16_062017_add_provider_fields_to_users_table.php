<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->constrained('providers')->nullOnDelete();
            $table->string('type')->default('provider_user');
            $table->index(['provider_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_id');
            $table->dropColumn('type');
        });
    }
};

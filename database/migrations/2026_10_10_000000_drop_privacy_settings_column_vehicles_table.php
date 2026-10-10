<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('vehicles', 'privacy_settings')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropColumn('privacy_settings');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('vehicles', 'privacy_settings')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->json('privacy_settings')->nullable();
            });
        }
    }
};

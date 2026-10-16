<?php

declare(strict_types=1);

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
        Schema::table('toll', function (Blueprint $table) {
            $table->renameColumn('date', 'start_date');
        });

        Schema::table('toll', function (Blueprint $table) {
            $table->date('end_date')->nullable()->after('start_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('toll', function (Blueprint $table) {
            $table->dropColumn('end_date');
        });

        Schema::table('toll', function (Blueprint $table) {
            $table->renameColumn('start_date', 'date');
        });
    }
};

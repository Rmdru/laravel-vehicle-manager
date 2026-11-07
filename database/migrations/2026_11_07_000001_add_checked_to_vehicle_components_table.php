<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a component was checked last and found in order, as the owner
     * marked a tip done: a check that does not restart its interval.
     */
    public function up(): void
    {
        Schema::table('vehicle_components', function (Blueprint $table) {
            $table->date('checked_date')->nullable()->after('done_km');
            $table->unsignedInteger('checked_km')->nullable()->after('checked_date');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_components', function (Blueprint $table) {
            $table->dropColumn(['checked_date', 'checked_km']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            // The places the driver chose to stop at, or not to stop at, by their ids.
            $table->json('pinned_stops')->nullable()->after('selected_route');
            $table->json('excluded_stops')->nullable()->after('pinned_stops');
            // Travel tips of an AI model, generated on request.
            $table->json('tips')->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['pinned_stops', 'excluded_stops', 'tips']);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The prices of the gas stations near the homes of the owners, per day:
     * the lowest, the highest and the last of the snapshots of the day. To
     * tell later what a refueling cost compared with the cheapest nearby.
     */
    public function up(): void
    {
        Schema::create('station_price_history', function (Blueprint $table) {
            $table->id();
            // The place of OpenStreetMap, and where it is: the place can be fetched anew.
            $table->unsignedBigInteger('poi_id');
            $table->string('name')->nullable();
            $table->char('country', 2)->nullable();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            // A fuel type of the app.
            $table->string('fuel_type', 40);
            $table->date('date');
            $table->decimal('lowest_price', 6, 3);
            $table->decimal('highest_price', 6, 3);
            $table->decimal('last_price', 6, 3);
            $table->unsignedSmallInteger('samples')->default(1);
            $table->timestamps();
            $table->unique(['poi_id', 'fuel_type', 'date']);
            $table->index(['date', 'latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_price_history');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The gas station a refueling was at, when it was chosen from the ones
     * nearby: where it is, so that its prices can be compared. Not a foreign
     * key: the places are fetched anew from OpenStreetMap now and then.
     */
    public function up(): void
    {
        Schema::table('refuelings', function (Blueprint $table) {
            $table->unsignedBigInteger('poi_id')->nullable()->after('gas_station')->index();
            $table->decimal('latitude', 9, 6)->nullable()->after('poi_id');
            $table->decimal('longitude', 9, 6)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('refuelings', function (Blueprint $table) {
            $table->dropColumn(['poi_id', 'latitude', 'longitude']);
        });
    }
};

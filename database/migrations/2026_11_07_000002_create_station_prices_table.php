<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The prices at the gas stations of the countries that publish all of
        // them in one file, imported every hour: the station map and the trip
        // planner read them here, rather than asking the source each time.
        Schema::create('station_prices', function (Blueprint $table) {
            $table->id();
            $table->char('country', 2);
            $table->string('source', 30);
            // The id of the station at the source.
            $table->string('source_id', 64);
            $table->string('name')->nullable();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            // One of e10, e5, super_plus, diesel, lpg or cng.
            $table->string('fuel', 20);
            // The fuel type of the app it is.
            $table->string('fuel_type', 40);
            $table->decimal('price', 6, 3);
            // Self service or served (as in Italy), where the source tells.
            $table->string('service', 10)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
            $table->index(['latitude', 'longitude']);
            $table->index('source');
        });

        // The version of the query a tile was fetched with: a tile of an older
        // one is fetched again, such as for all the charging stations.
        Schema::table('poi_tiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('version')->default(1)->after('fetched_at');
        });

        // The station map asks for the places of a kind within the bounds of the map.
        Schema::table('pois', function (Blueprint $table) {
            $table->index(['kind', 'latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_prices');

        Schema::table('pois', function (Blueprint $table) {
            $table->dropIndex(['kind', 'latitude', 'longitude']);
        });

        Schema::table('poi_tiles', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};

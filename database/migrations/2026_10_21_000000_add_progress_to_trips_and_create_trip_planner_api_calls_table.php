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
            // How far the planning is, while it is planned: kept with the trip,
            // so that the page and the queue worker always see the same.
            $table->json('progress')->nullable()->after('status');
        });

        // The calls to the APIs of the trip planner, counted per day: for the
        // daily limit of TomTom and to see how the Overpass servers do.
        Schema::create('trip_planner_api_calls', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('service', 32);
            $table->string('kind', 100);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedBigInteger('milliseconds')->default(0);
            $table->timestamps();

            $table->unique(['date', 'service', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_planner_api_calls');

        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('progress');
        });
    }
};

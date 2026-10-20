<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // No foreign key, as with the records of a vehicle: vehicles are soft deleted.
            $table->foreignUuid('vehicle_id');
            $table->string('name')->nullable();
            // Whether the time is the time of departure or the time of arrival.
            $table->string('time_mode', 10)->default('departure');
            $table->dateTime('time_at');
            // How full the tank is at departure, in percent.
            $table->unsignedTinyInteger('fuel_level')->default(100);
            $table->string('status', 10)->default('planning');
            // The planned routes with their stops; replanned whenever the trip changes.
            $table->json('plan')->nullable();
            $table->unsignedTinyInteger('selected_route')->default(0);
            // Raised whenever the trip changes: a planning of an older version is thrown away.
            $table->unsignedInteger('plan_version')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('planned_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'time_at']);
        });

        Schema::create('trip_waypoints', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('trip_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('label');
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            // How long the stay is at a destination along the way.
            $table->unsignedSmallInteger('stay_minutes')->default(0);
            $table->timestamps();
        });

        // Places from OpenStreetMap along the planned routes, cached per tile.
        Schema::create('pois', function (Blueprint $table) {
            $table->id();
            $table->char('osm_type', 1);
            $table->unsignedBigInteger('osm_id');
            $table->string('kind', 20);
            $table->string('name')->nullable();
            $table->string('brand')->nullable();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->json('tags');
            $table->string('tile', 20);
            $table->timestamps();
            $table->unique(['osm_type', 'osm_id']);
            $table->index('tile');
        });

        Schema::create('poi_tiles', function (Blueprint $table) {
            $table->string('tile', 20)->primary();
            $table->timestamp('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poi_tiles');
        Schema::dropIfExists('pois');
        Schema::dropIfExists('trip_waypoints');
        Schema::dropIfExists('trips');
    }
};

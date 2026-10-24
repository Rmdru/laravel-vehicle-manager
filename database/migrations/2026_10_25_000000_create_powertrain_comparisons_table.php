<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An alternative model per powertrain for a vehicle, as chosen by an AI
        // model, with its estimates of the vehicle itself: the costs are
        // calculated from them by the app.
        Schema::create('powertrain_comparisons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // No foreign key, as with the other records of a vehicle: vehicles
            // are soft deleted, so a cascade would not apply anyway.
            $table->foreignUuid('vehicle_id');
            $table->string('locale', 5);
            // The estimates of the vehicle itself, and the alternatives.
            $table->json('current');
            $table->json('alternatives');
            // The fingerprint of what the alternatives depend on: they are
            // chosen again when the vehicle changes.
            $table->string('input_hash', 64);
            $table->string('model');
            $table->timestamps();
            $table->unique(['vehicle_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('powertrain_comparisons');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_advice', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // No foreign key, as with the other records of a vehicle: vehicles
            // are soft deleted, so a cascade would not apply anyway.
            $table->foreignUuid('vehicle_id');
            $table->string('locale', 5);
            $table->json('suggestions');
            // What the advice was based on, and its fingerprint: the daily run
            // skips a vehicle whose facts did not change.
            $table->json('facts');
            $table->string('facts_hash', 64);
            $table->string('model');
            $table->timestamps();
            $table->index(['vehicle_id', 'locale', 'created_at']);
        });

        // Suggestions the driver marked as done or not relevant, by their key:
        // they are left out, and the model is told not to make them again.
        Schema::create('vehicle_advice_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('vehicle_id');
            $table->string('key');
            $table->string('reason');
            $table->string('title');
            $table->timestamps();
            $table->unique(['vehicle_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_advice_dismissals');
        Schema::dropIfExists('vehicle_advice');
    }
};

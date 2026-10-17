<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Left behind by a first run that failed on the foreign key, which
        // vehicles.id (without an index everywhere) cannot take.
        Schema::dropIfExists('fuel_advice');

        Schema::create('fuel_advice', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // No foreign key, as with the other records of a vehicle: vehicles
            // are soft deleted, so a cascade would not apply anyway.
            $table->foreignUuid('vehicle_id');
            $table->string('locale', 5);
            $table->string('action');
            $table->string('country')->nullable();
            $table->date('recommended_date')->nullable();
            $table->string('confidence');
            $table->string('summary');
            $table->text('body');
            $table->json('reasons');
            $table->json('sources');
            // What the advice was based on, to look back at whether it was right.
            $table->json('facts');
            $table->string('model');
            $table->boolean('grounded')->default(false);
            $table->timestamps();
            $table->index(['vehicle_id', 'locale', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_advice');
    }
};

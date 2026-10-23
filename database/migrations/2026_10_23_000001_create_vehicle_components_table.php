<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The maintenance plan of a vehicle: per component of
        // config('maintenance_plan.components') when it is due again.
        Schema::create('vehicle_components', function (Blueprint $table) {
            $table->id();
            // No foreign key, as with the other records of a vehicle: vehicles
            // are soft deleted, so a cascade would not apply anyway.
            $table->foreignUuid('vehicle_id');
            $table->string('key');
            // Off for a component the vehicle does not have, such as a timing chain instead of a belt.
            $table->boolean('active')->default(true);
            // Null: no interval of that kind.
            $table->unsignedInteger('interval_km')->nullable();
            $table->unsignedSmallInteger('interval_months')->nullable();
            // Where the intervals come from: the defaults, the AI model, or the owner.
            $table->string('source')->default('default');
            $table->text('note')->nullable();
            // When it was done last, as the owner knows it: for what was done
            // before it was recorded in the app. A later maintenance goes first.
            $table->date('done_date')->nullable();
            $table->unsignedInteger('done_km')->nullable();
            $table->timestamps();
            $table->unique(['vehicle_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_components');
    }
};

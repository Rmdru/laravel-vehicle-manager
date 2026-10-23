<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The summary of a month of a vehicle on the dashboard, as written by
        // an AI model from the facts the app calculated.
        Schema::create('vehicle_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // No foreign key, as with the other records of a vehicle: vehicles
            // are soft deleted, so a cascade would not apply anyway.
            $table->foreignUuid('vehicle_id');
            $table->string('locale', 5);
            // The month (YYYY-MM) and whether it is a look back at it or the month so far.
            $table->string('month', 7);
            $table->string('kind');
            $table->string('headline');
            $table->text('text');
            // What the summary was based on, and its fingerprint: it is only
            // written again when the facts changed.
            $table->json('facts');
            $table->string('facts_hash', 64);
            $table->string('model');
            $table->timestamps();
            $table->index(['vehicle_id', 'locale', 'month', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_summaries');
    }
};

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

        // The tips the owner did something with, by their key: done, kept for
        // later reference, or not relevant. With a copy of the tip, so that it
        // stays in the overview after the advice it came from is pruned. The
        // model is told not to make these tips again.
        Schema::create('vehicle_tips', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('vehicle_id');
            $table->string('key');
            $table->string('status');
            $table->string('locale', 5);
            $table->string('category');
            // Null when the model chose none of the known icons: the icon of the category is shown.
            $table->string('icon')->nullable();
            $table->string('priority');
            $table->string('basis');
            $table->string('action');
            $table->date('due_date')->nullable();
            $table->string('title');
            $table->text('body');
            $table->timestamps();
            $table->unique(['vehicle_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_tips');
        Schema::dropIfExists('vehicle_advice');
    }
};

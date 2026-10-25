<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What needs attention on a vehicle until it is resolved: what the garage
        // advised, what the owner noticed, and the defects the MOT found.
        Schema::create('findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // No foreign key, as with the other records of a vehicle: vehicles are soft deleted.
            $table->foreignUuid('vehicle_id');
            $table->string('source', 20);
            $table->string('severity', 10);
            $table->string('status', 10)->default('open');
            $table->date('date');
            $table->unsignedInteger('mileage')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            // Who advised it, for advice of the garage.
            $table->string('garage', 100)->nullable();
            $table->decimal('estimated_cost', 10, 2)->nullable();
            // The components of the maintenance plan it is about.
            $table->json('components')->nullable();
            // On the list for the next visit to the garage.
            $table->boolean('for_garage')->default(false);
            $table->date('resolved_date')->nullable();
            // The maintenance it was resolved with.
            $table->foreignUuid('maintenance_id')->nullable();
            // The defect of the MOT the RDW registered, so that it is imported once.
            $table->string('rdw_code', 20)->nullable();
            // What the AI model makes of a complaint, when the owner asked.
            $table->json('diagnosis')->nullable();
            $table->timestamp('diagnosed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['vehicle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};

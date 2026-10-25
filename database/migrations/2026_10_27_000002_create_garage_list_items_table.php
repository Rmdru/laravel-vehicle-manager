<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the owner puts on the list for the garage by itself, without it
        // being a finding: a question, a part to bring, a small job.
        Schema::create('garage_list_items', function (Blueprint $table) {
            $table->id();
            // No foreign key, as with the other records of a vehicle: vehicles are soft deleted.
            $table->foreignUuid('vehicle_id');
            $table->string('text');
            $table->timestamps();
            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garage_list_items');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The notifications of a vehicle as they come and go: what "Needs
        // attention" shows, so that one can be dismissed and looked back on.
        Schema::create('vehicle_notifications', function (Blueprint $table) {
            $table->id();
            // No foreign key, as with the other records of a vehicle: vehicles
            // are soft deleted, so a cascade would not apply anyway.
            $table->foreignUuid('vehicle_id');
            // What it is about, such as "apk" or "maintenance_plan:brake_fluid": one open notification per key.
            $table->string('key');
            $table->string('type');
            $table->string('text');
            // The notification as the dashboard shows it, with its icon, link and details.
            $table->json('payload');
            // Of the type and the text: a dismissed notification comes back when it changes.
            $table->string('fingerprint', 64);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('dismissed_at')->nullable();
            // When it no longer applied; a notification that comes back after that is a new one.
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['vehicle_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_notifications');
    }
};

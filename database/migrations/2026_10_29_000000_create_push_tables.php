<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The devices of a user that receive push notifications: one per
        // browser on a phone or computer, as the browser subscribed it.
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The address of the device at the push service of the browser: long, so unique by its hash.
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding')->default('aes128gcm');
            // To tell the devices apart on the settings page.
            $table->string('user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        // What is sent, or is to be sent, to the devices of the owner of a
        // vehicle: a notification of the bell, new tips, the monthly summary.
        Schema::create('push_messages', function (Blueprint $table) {
            $table->id();
            // No foreign key, as with the other records of a vehicle: vehicles are soft deleted.
            $table->foreignUuid('vehicle_id');
            // A notification of the bell, "tips" or "summary".
            $table->string('kind');
            // Critical, warning or info: when it is sent and how it looks.
            $table->string('urgency');
            $table->foreignId('vehicle_notification_id')->nullable()->constrained()->cascadeOnDelete();
            // One message per subject and version, such as a notification and its fingerprint.
            $table->string('reference');
            $table->string('title');
            $table->text('body');
            $table->string('icon')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('send_after');
            $table->timestamp('sent_at')->nullable();
            // No longer to be sent: it no longer applied, or the owner saw it in the app first.
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['vehicle_id', 'reference']);
            $table->index(['sent_at', 'cancelled_at', 'send_after']);
        });

        // Which push notifications the owner wants for a vehicle, and when.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->json('push_preferences')->nullable()->after('notifications');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('push_preferences');
        });

        Schema::dropIfExists('push_messages');
        Schema::dropIfExists('push_subscriptions');
    }
};

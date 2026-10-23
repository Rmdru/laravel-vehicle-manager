<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When a notification was new to the owner: when it appeared, or came
        // back because it changed. The bell counts the ones since they looked.
        Schema::table('vehicle_notifications', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('last_seen_at');
        });

        DB::table('vehicle_notifications')->update(['notified_at' => DB::raw('first_seen_at')]);

        // When the user last looked at the notifications.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('notifications_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notifications_seen_at');
        });

        Schema::table('vehicle_notifications', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};

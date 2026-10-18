<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable();
        });

        // Users with a session were around lately, so they keep their AI features.
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')
                ->whereNotNull('user_id')
                ->selectRaw('user_id, max(last_activity) as last_activity')
                ->groupBy('user_id')
                ->get()
                ->each(fn ($session) => DB::table('users')
                    ->where('id', $session->user_id)
                    ->update(['last_login_at' => date('Y-m-d H:i:s', (int) $session->last_activity)]));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};

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
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('transmission')->default('manual')->after('body_type');
        });

        // Electric vehicles are always automatic.
        DB::table('vehicles')->where('powertrain', 'electricity')->update(['transmission' => 'automatic']);
        DB::table('vehicles')->whereNull('transmission')->update(['transmission' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('transmission');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The maintenance types that restarted the single interval. */
    private const TYPES = ['small_maintenance', 'maintenance', 'big_maintenance', 'oil_service', 'inspection_service', 'longlife'];

    public function up(): void
    {
        // Several intervals instead of one, such as an inspection service and
        // an oil service: per interval the maintenance types that restart it,
        // and its interval in kilometers and in months.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->json('maintenance_intervals')->nullable()->after('tank_capacity');
        });

        // The single interval becomes one that any maintenance restarts.
        DB::table('vehicles')->orderBy('id')->each(function (object $vehicle) {
            DB::table('vehicles')->where('id', $vehicle->id)->update(['maintenance_intervals' => json_encode([[
                'types' => self::TYPES,
                'km' => (int) $vehicle->maintenance_interval_distance > 0 ? (int) $vehicle->maintenance_interval_distance : null,
                'months' => (int) $vehicle->maintenance_interval_time > 0 ? (int) $vehicle->maintenance_interval_time : 12,
            ]])]);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['maintenance_interval_distance', 'maintenance_interval_time']);
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->integer('maintenance_interval_distance')->default(15000)->after('tank_capacity');
            $table->integer('maintenance_interval_time')->default(12)->after('maintenance_interval_distance');
        });

        // The first interval goes back.
        DB::table('vehicles')->orderBy('id')->each(function (object $vehicle) {
            $interval = json_decode((string) $vehicle->maintenance_intervals, true)[0] ?? [];

            DB::table('vehicles')->where('id', $vehicle->id)->update([
                'maintenance_interval_distance' => (int) ($interval['km'] ?? 0),
                'maintenance_interval_time' => (int) ($interval['months'] ?? 12),
            ]);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('maintenance_intervals');
        });
    }
};

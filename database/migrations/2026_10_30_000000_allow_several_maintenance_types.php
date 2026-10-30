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
        // Several types per maintenance, such as an oil service and an
        // inspection service done together: a list instead of one.
        Schema::table('maintenances', function (Blueprint $table) {
            $table->json('type_maintenance_list')->nullable()->after('type_maintenance');
        });

        DB::table('maintenances')->whereNotNull('type_maintenance')->where('type_maintenance', '!=', '')->orderBy('id')->each(function (object $maintenance) {
            DB::table('maintenances')->where('id', $maintenance->id)->update(['type_maintenance_list' => json_encode([$maintenance->type_maintenance])]);
        });

        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('type_maintenance');
        });

        Schema::table('maintenances', function (Blueprint $table) {
            $table->renameColumn('type_maintenance_list', 'type_maintenance');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('type_maintenance_single', 100)->nullable()->after('type_maintenance');
        });

        // The first type goes back.
        DB::table('maintenances')->whereNotNull('type_maintenance')->orderBy('id')->each(function (object $maintenance) {
            DB::table('maintenances')->where('id', $maintenance->id)->update(['type_maintenance_single' => json_decode((string) $maintenance->type_maintenance, true)[0] ?? null]);
        });

        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('type_maintenance');
        });

        Schema::table('maintenances', function (Blueprint $table) {
            $table->renameColumn('type_maintenance_single', 'type_maintenance');
        });
    }
};

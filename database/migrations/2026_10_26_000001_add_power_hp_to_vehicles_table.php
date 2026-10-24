<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In hp; of a hybrid the system power, which the RDW does not know.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedSmallInteger('power_hp')->nullable()->after('engine');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('power_hp');
        });
    }
};

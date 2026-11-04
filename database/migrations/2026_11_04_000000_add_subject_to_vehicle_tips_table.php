<?php

declare(strict_types=1);

use App\Models\VehicleAdvice;
use App\Models\VehicleTip;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_tips', function (Blueprint $table) {
            $table->string('subject')->nullable()->after('key');
        });

        VehicleTip::query()->chunkById(100, function ($tips) {
            foreach ($tips as $tip) {
                $tip->update(['subject' => VehicleAdvice::inferSubject($tip->key, $tip->category)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_tips', function (Blueprint $table) {
            $table->dropColumn('subject');
        });
    }
};

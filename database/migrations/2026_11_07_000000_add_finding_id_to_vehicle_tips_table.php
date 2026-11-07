<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The open finding a tip is about, so that a finding already on the
     * garage list gets a tip only once. Not a foreign key: the tip stays when
     * the finding is deleted.
     */
    public function up(): void
    {
        Schema::table('vehicle_tips', function (Blueprint $table) {
            $table->uuid('finding_id')->nullable()->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_tips', function (Blueprint $table) {
            $table->dropColumn('finding_id');
        });
    }
};

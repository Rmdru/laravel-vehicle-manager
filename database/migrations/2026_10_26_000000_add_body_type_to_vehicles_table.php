<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Such as a hatchback or a station wagon, see BodyType; unknown for most vehicles until it is filled in.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('body_type')->nullable()->after('engine');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('body_type');
        });
    }
};

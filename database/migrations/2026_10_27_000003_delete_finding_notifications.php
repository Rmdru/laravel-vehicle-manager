<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The findings no longer show under "Needs attention": the ones stored
        // would otherwise stay in the history as no longer applying.
        DB::table('vehicle_notifications')->where('key', 'like', 'finding:%')->delete();
    }

    public function down(): void
    {
        //
    }
};

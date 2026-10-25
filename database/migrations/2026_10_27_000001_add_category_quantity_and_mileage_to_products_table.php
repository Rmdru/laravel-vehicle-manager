<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // What kind of product: engine oil or coolant topped up tells about the engine.
            $table->string('category', 20)->nullable()->after('name');
            // Liters, for a fluid.
            $table->decimal('quantity', 6, 2)->nullable()->after('category');
            // The mileage it was topped up at: what makes it a top-up.
            $table->unsignedInteger('mileage')->nullable()->after('quantity');
            // Topped up from a bottle bought before: nothing paid.
            $table->float('price')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['category', 'quantity', 'mileage']);
        });
    }
};

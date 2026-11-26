<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The news the tips can be about, read from the sources of
     * config/vehicle_news.php: for every vehicle it applies to, not per owner.
     */
    public function up(): void
    {
        Schema::create('vehicle_news', function (Blueprint $table) {
            $table->id();
            // The address itself can be long: the hash keeps it unique.
            $table->char('url_hash', 64)->unique();
            $table->text('url');
            $table->string('source');
            $table->string('title');
            // In English, as the facts are.
            $table->text('summary');
            $table->string('kind', 30);
            $table->date('published_on')->nullable();
            // The countries it applies to (empty: all), and the vehicles (empty: all).
            $table->json('countries');
            $table->json('applies_to');
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::table('vehicle_tips', function (Blueprint $table) {
            // The article a tip is based on: where it comes from, kept with the tip.
            $table->json('source')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_tips', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::dropIfExists('vehicle_news');
    }
};

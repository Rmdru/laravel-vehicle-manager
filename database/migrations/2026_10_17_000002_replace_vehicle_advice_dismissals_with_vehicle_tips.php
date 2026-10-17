<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The dismissals only held the key and title of a tip, too little to
        // show it in the overview: they are not carried over.
        Schema::dropIfExists('vehicle_advice_dismissals');

        // Already there where the first version of the advice migration created it.
        if (Schema::hasTable('vehicle_tips')) {
            return;
        }

        // The tips the owner did something with, by their key: done, kept for
        // later reference, or not relevant. With a copy of the tip, so that it
        // stays in the overview after the advice it came from is pruned. The
        // model is told not to make these tips again.
        Schema::create('vehicle_tips', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('vehicle_id');
            $table->string('key');
            $table->string('status');
            $table->string('locale', 5);
            $table->string('category');
            // Null when the model chose none of the known icons: the icon of the category is shown.
            $table->string('icon')->nullable();
            $table->string('priority');
            $table->string('basis');
            $table->string('action');
            $table->date('due_date')->nullable();
            $table->string('title');
            $table->text('body');
            $table->timestamps();
            $table->unique(['vehicle_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_tips');

        Schema::create('vehicle_advice_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('vehicle_id');
            $table->string('key');
            $table->string('reason');
            $table->string('title');
            $table->timestamps();
            $table->unique(['vehicle_id', 'key']);
        });
    }
};

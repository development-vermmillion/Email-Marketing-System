<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately has NO foreign keys: suppression must survive contact deletion, re-import
        // and campaign clean-up.
        Schema::create('suppression_list', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('reason', 20);          // unsubscribed | bounced | complained | manual
            $table->string('source', 50)->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppression_list');
    }
};

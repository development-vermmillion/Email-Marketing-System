<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audiences', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email'); // always stored lower-cased
            $table->string('phone', 50)->nullable();
            $table->string('company')->nullable();
            $table->string('status', 20)->default('active');                  // active | inactive
            $table->string('subscription_status', 20)->default('subscribed'); // subscribed | unsubscribed | bounced | complained
            $table->string('source', 50)->default('manual');                  // manual | csv_import | api
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->foreignId('csv_import_id')->nullable()->index();
            $table->timestamps();
            // Soft delete: removes the contact from active use immediately while
            // keeping campaign_recipients history and suppression rows intact.
            $table->softDeletes();

            $table->unique('email');
            $table->index(['status', 'subscription_status']);
            $table->index('name');
        });

        Schema::create('audience_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->string('color', 9)->default('#5b4cf5');
            $table->timestamps();
        });

        Schema::create('audience_group_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audience_id')->constrained('audiences')->cascadeOnDelete();
            $table->foreignId('audience_group_id')->constrained('audience_groups')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['audience_id', 'audience_group_id'], 'agc_unique_pair');
            $table->index('audience_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_group_contacts');
        Schema::dropIfExists('audience_groups');
        Schema::dropIfExists('audiences');
    }
};

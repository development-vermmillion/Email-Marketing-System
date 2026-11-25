<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            // Keeps working if the contact is force-deleted: history lives in the snapshot columns.
            $table->foreignId('audience_id')->nullable()->constrained('audiences')->nullOnDelete();

            // Snapshot of the contact at the time the campaign was prepared.
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('company')->nullable();
            $table->string('phone', 50)->nullable();

            // pending | sending | sent | delivered | failed | bounced | complained | skipped
            $table->string('status', 20)->default('pending');
            $table->string('tracking_token', 64)->unique(); // unguessable; used for unsubscribe/open/click URLs
            $table->string('message_id')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('first_clicked_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();

            $table->text('bounce_reason')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('failure_type', 12)->nullable(); // temporary | permanent
            $table->timestamps();

            // The unique pair is what stops a contact in several groups receiving the same campaign twice.
            $table->unique(['campaign_id', 'email']);
            $table->index(['campaign_id', 'status']);
            $table->index('message_id');
            $table->index('email');
            $table->index('audience_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};

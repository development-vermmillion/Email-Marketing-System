<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per SMTP send attempt.
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained('campaign_recipients')->cascadeOnDelete();
            $table->foreignId('smtp_setting_id')->nullable()->constrained('smtp_settings')->nullOnDelete();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('status', 12);                    // sent | failed | deferred
            $table->string('message_id')->nullable();
            $table->string('smtp_code', 10)->nullable();
            $table->text('response')->nullable();            // never contains credentials
            $table->boolean('is_permanent')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['campaign_id', 'status']);
            $table->index('campaign_recipient_id');
            $table->index(['smtp_setting_id', 'status', 'created_at'], 'email_logs_daily_idx');
        });

        // Provider webhooks + tracking events (normalised) with the raw payload kept for audit.
        Schema::create('email_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained('campaign_recipients')->cascadeOnDelete();
            $table->string('provider', 30);                  // generic | sendgrid | mailgun | postmark | ses | tracking
            $table->string('event_type', 20);                // accepted | delivered | bounced | complained | opened | clicked | unsubscribed
            $table->string('provider_event_id')->nullable();
            $table->char('dedupe_key', 64)->unique();        // sha256: makes webhook retries harmless
            $table->string('email')->nullable();
            $table->text('url')->nullable();
            $table->string('bounce_type', 10)->nullable();   // hard | soft
            $table->text('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['campaign_id', 'event_type']);
            $table->index('campaign_recipient_id');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_events');
        Schema::dropIfExists('email_logs');
    }
};

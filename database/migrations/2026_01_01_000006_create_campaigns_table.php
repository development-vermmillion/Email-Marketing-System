<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subject');
            $table->string('from_name');
            $table->string('from_email');
            $table->string('reply_to_email')->nullable();
            // A template that is in use cannot be deleted.
            $table->foreignId('email_template_id')->constrained('email_templates')->restrictOnDelete();
            $table->foreignId('smtp_setting_id')->nullable()->constrained('smtp_settings')->nullOnDelete();
            $table->json('group_ids')->nullable();      // selected audience groups; null/[] = all active contacts
            $table->longText('html_snapshot')->nullable(); // template frozen when sending starts
            $table->string('subject_snapshot')->nullable();

            $table->string('status', 20)->default('draft');
            $table->string('status_before_trash', 20)->nullable();
            $table->string('status_note')->nullable();   // e.g. why a campaign paused/failed automatically

            // Sending controls (null = fall back to SMTP setting, then config/mailing.php)
            $table->unsignedInteger('rate_per_second')->nullable();
            $table->unsignedInteger('rate_per_minute')->nullable();
            $table->unsignedInteger('batch_size')->nullable();
            $table->unsignedTinyInteger('max_attempts')->nullable();
            $table->unsignedInteger('retry_delay')->nullable(); // seconds
            $table->unsignedInteger('daily_limit')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Denormalised counters, recomputed from campaign_recipients by CampaignStatsService.
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('queued_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('complained_count')->default(0);
            $table->unsignedInteger('unsubscribed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes(); // trash: permanently removed after 7 days by the scheduler

            $table->index(['status', 'scheduled_at']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};

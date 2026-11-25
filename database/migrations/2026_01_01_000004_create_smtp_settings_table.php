<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Used to select the webhook adapter: generic, sendgrid, mailgun, postmark, ses
            $table->string('provider', 30)->default('generic');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('encryption', 10)->default('tls'); // tls | ssl | none
            // Encrypted at rest through the model's `encrypted` cast, hence text columns.
            $table->text('username')->nullable();
            $table->text('password')->nullable();
            $table->string('from_name');
            $table->string('from_email');
            $table->string('reply_to_email')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('rate_per_second')->nullable();
            $table->unsignedInteger('rate_per_minute')->nullable();
            $table->unsignedInteger('daily_limit')->nullable();
            $table->text('webhook_secret')->nullable(); // encrypted
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_settings');
    }
};

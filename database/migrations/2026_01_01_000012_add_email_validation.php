<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audiences', function (Blueprint $table) {
            $table->string('email_validation_status', 12)->nullable()->after('subscription_status'); // valid | risky | invalid | null = not checked
            $table->string('email_validation_reason', 30)->nullable()->after('email_validation_status');
            $table->timestamp('email_validated_at')->nullable()->after('email_validation_reason');
            $table->index('email_validation_status');
        });

        Schema::create('email_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('pending'); // pending | processing | completed | failed | cancelled
            $table->string('scope', 20);                      // unchecked | all | group
            $table->unsignedBigInteger('group_id')->nullable();
            $table->boolean('deactivate_invalid')->default(false);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('risky_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedBigInteger('last_id')->default(0); // checkpoint so a restarted job resumes where it stopped
            $table->string('error', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_validation_runs');
        Schema::table('audiences', function (Blueprint $table) {
            $table->dropIndex(['email_validation_status']);
            $table->dropColumn(['email_validation_status', 'email_validation_reason', 'email_validated_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail. campaign_id is nullable so the same table also backs the global "Activity Logs" screen.
        Schema::create('campaign_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('log_name', 30)->default('campaign'); // campaign | audience | import | smtp | template | auth | system | suppression
            $table->string('action', 60);
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['campaign_id', 'created_at']);
            $table->index(['log_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_activities');
    }
};

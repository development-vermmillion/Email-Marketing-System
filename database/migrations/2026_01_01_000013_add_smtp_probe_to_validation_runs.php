<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_validation_runs', function (Blueprint $table) {
            $table->boolean('smtp_probe')->default(false)->after('deactivate_invalid');
        });
    }

    public function down(): void
    {
        Schema::table('email_validation_runs', fn (Blueprint $table) => $table->dropColumn('smtp_probe'));
    }
};

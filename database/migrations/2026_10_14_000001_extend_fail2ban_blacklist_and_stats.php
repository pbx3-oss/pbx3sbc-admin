<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fail2ban_blacklist', function (Blueprint $table) {
            $table->string('source', 16)->default('manual')->after('reason')
                ->comment('manual|auto');
            $table->unsignedInteger('ban_count_at_promote')->nullable()->after('source')
                ->comment('Temp-ban count when auto-promoted');
        });

        Schema::create('fail2ban_ban_stats', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique();
            $table->unsignedInteger('ban_count')->default(0);
            $table->timestamp('first_banned_at')->nullable();
            $table->timestamp('last_banned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fail2ban_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('auto_perma_threshold')->default(10)
                ->comment('0 = auto-promote disabled');
            $table->timestamps();
        });

        if (DB::table('fail2ban_settings')->count() === 0) {
            DB::table('fail2ban_settings')->insert([
                'auto_perma_threshold' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fail2ban_settings');
        Schema::dropIfExists('fail2ban_ban_stats');

        Schema::table('fail2ban_blacklist', function (Blueprint $table) {
            $table->dropColumn(['source', 'ban_count_at_promote']);
        });
    }
};

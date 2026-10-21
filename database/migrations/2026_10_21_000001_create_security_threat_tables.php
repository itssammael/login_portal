<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_threat_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('ip_address', 45);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('threat_type', 50)->index();
            $table->string('severity', 20)->default('medium')->index();
            $table->text('url');
            $table->string('method', 10)->default('GET');
            $table->text('user_agent')->nullable();
            $table->json('payload')->nullable();
            $table->string('action_taken', 50)->default('logged');
            $table->boolean('is_resolved')->default(false)->index();
            $table->timestamps();

            $table->index(['ip_address', 'created_at']);
            $table->index(['threat_type', 'created_at']);
        });

        Schema::create('security_blocked_ips', function (Blueprint $table): void {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->string('reason');
            $table->unsignedInteger('threat_count')->default(1);
            $table->string('blocked_by')->default('self_healing_engine');
            $table->timestamp('blocked_until')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('security_healing_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('action_type', 60)->index();
            $table->text('description');
            $table->string('target')->nullable();
            $table->string('status', 30)->default('applied');
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_healing_logs');
        Schema::dropIfExists('security_blocked_ips');
        Schema::dropIfExists('security_threat_logs');
    }
};

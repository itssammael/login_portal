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
        Schema::table('security_threat_logs', function (Blueprint $table): void {
            $table->string('client_ipv4', 45)->nullable()->after('ip_address')->index();
            $table->string('client_ipv6', 45)->nullable()->after('client_ipv4')->index();
        });

        Schema::table('security_blocked_ips', function (Blueprint $table): void {
            $table->string('client_ipv4', 45)->nullable()->after('ip_address');
            $table->string('client_ipv6', 45)->nullable()->after('client_ipv4');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('security_blocked_ips', function (Blueprint $table): void {
            $table->dropColumn(['client_ipv4', 'client_ipv6']);
        });

        Schema::table('security_threat_logs', function (Blueprint $table): void {
            $table->dropColumn(['client_ipv4', 'client_ipv6']);
        });
    }
};

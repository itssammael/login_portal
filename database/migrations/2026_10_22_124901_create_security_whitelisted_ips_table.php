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
        Schema::create('security_whitelisted_ips', function (Blueprint $table): void {
            $table->id();
            $table->string('ip_address', 50)->unique();
            $table->string('label', 150);
            $table->text('description')->nullable();
            $table->string('added_by', 100)->default('system');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        DB::table('security_whitelisted_ips')->insert([
            [
                'ip_address' => '127.0.0.1',
                'label' => 'Localhost Loopback (IPv4)',
                'description' => 'System default trusted local loopback address.',
                'added_by' => 'system_default',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'ip_address' => '::1',
                'label' => 'Localhost Loopback (IPv6)',
                'description' => 'System default trusted IPv6 loopback address.',
                'added_by' => 'system_default',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'ip_address' => '124.104.144.26',
                'label' => 'Host Server (IPv4)',
                'description' => 'System Host server address.',
                'added_by' => 'system_default',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'ip_address' => '112.208.65.92',
                'label' => 'Developer Home Address (IPv4)',
                'description' => 'System Developer Home Address.',
                'added_by' => 'system_default',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_whitelisted_ips');
    }
};

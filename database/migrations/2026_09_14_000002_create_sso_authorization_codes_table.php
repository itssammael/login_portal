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
        Schema::create('sso_authorization_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('client_id');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('redirect_uri');
            $table->string('code_challenge')->nullable();
            $table->string('code_challenge_method')->default('S256');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sso_authorization_codes');
    }
};

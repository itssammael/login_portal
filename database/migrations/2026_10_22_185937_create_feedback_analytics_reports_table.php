<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_analytics_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_id')->constrained('feedback')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->string('visibility')->default('private');
            $table->json('configuration');
            $table->json('published_configuration')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unpublished_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::table('feedback', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_analytics_report_id')->nullable()->constrained('feedback_analytics_reports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_analytics_report_id');
            $table->dropConstrainedForeignId('created_by');
        });
        Schema::dropIfExists('feedback_analytics_reports');
    }
};

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
        if (Schema::hasTable('sso')) {
            Schema::table('sso', function (Blueprint $table) {
                if (! Schema::hasColumn('sso', 'description')) {
                    $table->text('description')->nullable()->after('name');
                }
                if (! Schema::hasColumn('sso', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->after('framework')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('sso', 'last_used_at')) {
                    $table->timestamp('last_used_at')->nullable()->after('is_active');
                }
            });
        }

        if (Schema::hasTable('sso_authorization_codes')) {
            Schema::table('sso_authorization_codes', function (Blueprint $table) {
                if (! Schema::hasColumn('sso_authorization_codes', 'nonce')) {
                    $table->string('nonce')->nullable()->after('code_challenge_method');
                }
            });
        }

        if (Schema::hasTable('sso_user_bindings')) {
            Schema::table('sso_user_bindings', function (Blueprint $table) {
                if (! Schema::hasColumn('sso_user_bindings', 'last_login_at')) {
                    $table->timestamp('last_login_at')->nullable()->after('is_verified');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sso_user_bindings')) {
            Schema::table('sso_user_bindings', function (Blueprint $table) {
                if (Schema::hasColumn('sso_user_bindings', 'last_login_at')) {
                    $table->dropColumn('last_login_at');
                }
            });
        }

        if (Schema::hasTable('sso_authorization_codes')) {
            Schema::table('sso_authorization_codes', function (Blueprint $table) {
                if (Schema::hasColumn('sso_authorization_codes', 'nonce')) {
                    $table->dropColumn('nonce');
                }
            });
        }

        if (Schema::hasTable('sso')) {
            Schema::table('sso', function (Blueprint $table) {
                if (Schema::hasColumn('sso', 'last_used_at')) {
                    $table->dropColumn('last_used_at');
                }
                if (Schema::hasColumn('sso', 'created_by')) {
                    $table->dropForeign(['created_by']);
                    $table->dropColumn('created_by');
                }
                if (Schema::hasColumn('sso', 'description')) {
                    $table->dropColumn('description');
                }
            });
        }
    }
};

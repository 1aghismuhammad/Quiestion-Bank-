<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->exists() || DB::table('password_reset_tokens')->exists()) {
            throw new RuntimeException(
                'Google OAuth migration requires empty legacy users and password reset tokens.',
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_id')->unique()->after('id');
            $table->string('avatar_url', 2048)->nullable()->after('email');
            $table->string('phone_number', 32)->nullable()->index()->after('avatar_url');
            $table->timestamp('phone_verified_at')->nullable()->after('phone_number');
            $table->boolean('marketing_consent')->default(false)->after('phone_verified_at');
            $table->string('status', 32)->default(UserStatus::ACTIVE->value)->index()->after('marketing_consent');
            $table->timestamp('last_login_at')->nullable()->after('status');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['email_verified_at', 'password', 'remember_token']);
        });

        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        if (DB::table('users')->exists()) {
            throw new RuntimeException(
                'Cannot roll back Google OAuth migration while OAuth users exist.',
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->string('password')->nullable()->after('email_verified_at');
            $table->rememberToken();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['phone_number']);
            $table->dropIndex(['status']);
            $table->dropUnique(['google_id']);
            $table->dropColumn([
                'google_id',
                'avatar_url',
                'phone_number',
                'phone_verified_at',
                'marketing_consent',
                'status',
                'last_login_at',
            ]);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};

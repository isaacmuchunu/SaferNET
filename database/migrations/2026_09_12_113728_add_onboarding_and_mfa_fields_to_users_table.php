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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->index();
            $table->timestamp('temporary_password_expires_at')->nullable();
            $table->boolean('mfa_required')->default(false)->index();
            $table->text('mfa_secret')->nullable();
            $table->timestamp('mfa_enabled_at')->nullable();
            $table->text('mfa_recovery_codes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'must_change_password',
                'temporary_password_expires_at',
                'mfa_required',
                'mfa_secret',
                'mfa_enabled_at',
                'mfa_recovery_codes',
            ]);
        });
    }
};

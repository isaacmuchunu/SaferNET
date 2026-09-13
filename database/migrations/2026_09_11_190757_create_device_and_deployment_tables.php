<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('location')->nullable();
            $table->timestamps();
            $table->unique(['institution_id', 'name']);
            $table->unique(['id', 'institution_id']);
        });

        Schema::create('device_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('purpose')->nullable();
            $table->timestamps();
            $table->unique(['institution_id', 'name']);
            $table->unique(['id', 'institution_id']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('laboratory_id')->nullable();
            $table->foreignId('device_group_id')->nullable();
            $table->string('asset_tag');
            $table->string('serial_number')->nullable();
            $table->string('hostname')->nullable();
            $table->string('platform')->default('windows');
            $table->string('usage_type')->default('learner');
            $table->string('status')->default('active')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['institution_id', 'asset_tag']);
            $table->unique(['id', 'institution_id']);
            $table->index(['institution_id', 'status']);
            $table->foreign(['laboratory_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('laboratories');
            $table->foreign(['device_group_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('device_groups');
        });

        Schema::create('device_learner_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id');
            $table->foreignId('learner_id');
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_reason')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'removed_at']);
            $table->index(['learner_id', 'removed_at']);
            $table->foreign(['device_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('devices')->cascadeOnDelete();
            $table->foreign(['learner_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('learners')->cascadeOnDelete();
        });

        Schema::create('learner_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id');
            $table->foreignId('learner_id');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('identity_source');
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'ended_at']);
            $table->index(['learner_id', 'started_at']);
            $table->unique(['id', 'institution_id']);
            $table->foreign(['device_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('devices')->cascadeOnDelete();
            $table->foreign(['learner_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('learners')->cascadeOnDelete();
        });

        Schema::create('protection_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable();
            $table->string('type');
            $table->string('identifier');
            $table->string('version')->nullable();
            $table->string('health_status')->default('unknown')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('policy_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['institution_id', 'type', 'identifier']);
            $table->index(['institution_id', 'type', 'health_status']);
            $table->foreign(['device_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('devices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protection_components');
        Schema::dropIfExists('learner_sessions');
        Schema::dropIfExists('device_learner_assignments');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('device_groups');
        Schema::dropIfExists('laboratories');
    }
};

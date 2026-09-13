<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learner_id');
            $table->foreignId('device_id');
            $table->foreignId('filtering_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('policy_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('content_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('filtering_violation');
            $table->string('severity')->index();
            $table->string('status')->default('open')->index();
            $table->unsignedInteger('event_count')->default(1);
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('notified_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->timestamps();
            $table->index(['institution_id', 'status', 'severity']);
            $table->index(['learner_id', 'last_detected_at']);
            $table->unique(['id', 'institution_id']);
            $table->foreign(['learner_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('learners')->restrictOnDelete();
            $table->foreign(['device_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('devices')->restrictOnDelete();
        });

        Schema::create('web_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learner_session_id');
            $table->foreignId('learner_id');
            $table->foreignId('device_id');
            $table->foreignId('filtering_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('policy_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('content_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('incident_id')->nullable();
            $table->text('url');
            $table->string('domain')->index();
            $table->string('request_kind');
            $table->string('action');
            $table->string('enforcement_source');
            $table->string('severity');
            $table->string('reason');
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['learner_id', 'action', 'request_kind', 'occurred_at'], 'web_events_violation_lookup');
            $table->index(['institution_id', 'occurred_at']);
            $table->foreign(['learner_session_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('learner_sessions')->restrictOnDelete();
            $table->foreign(['learner_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('learners')->restrictOnDelete();
            $table->foreign(['device_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('devices')->restrictOnDelete();
            $table->foreign(['incident_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('incidents')->restrictOnDelete();
        });

        Schema::create('incident_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->foreign(['incident_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('incidents')->cascadeOnDelete();
        });

        Schema::create('exception_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('content_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain');
            $table->text('reason');
            $table->string('status')->default('pending')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id');
            $table->foreignId('learner_session_id')->nullable();
            $table->foreignId('learner_id')->nullable();
            $table->foreignId('incident_id')->nullable();
            $table->string('type');
            $table->string('severity')->index();
            $table->text('description');
            $table->string('response')->nullable();
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['institution_id', 'occurred_at']);
            $table->foreign(['device_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('devices')->cascadeOnDelete();
            $table->foreign(['learner_session_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('learner_sessions')->restrictOnDelete();
            $table->foreign(['learner_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('learners')->restrictOnDelete();
            $table->foreign(['incident_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('incidents')->restrictOnDelete();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['institution_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('exception_requests');
        Schema::dropIfExists('incident_actions');
        Schema::dropIfExists('web_events');
        Schema::dropIfExists('incidents');
    }
};

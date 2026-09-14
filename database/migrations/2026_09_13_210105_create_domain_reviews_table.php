<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The queue of domains learners reached that no blocklist knows about.
 *
 * Upstream lists cover what was already known when they were published, which
 * is the one thing a new site never is. This table is how real learner traffic
 * closes that gap: a domain nobody has classified is triaged, a classifier
 * offers an opinion, and an officer decides. The decision is county-wide, so
 * one school's discovery protects every school.
 *
 * Deliberately not scoped to an institution. A domain is a property of the
 * internet, not of a school, and the counts here describe how widely it was
 * seen precisely so a reviewer can weigh that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 253)->unique();

            // How widely it was seen, which is what makes a queue reviewable:
            // one curious learner is not the same signal as a whole year group.
            $table->unsignedInteger('learners_seen')->default(0);
            $table->unsignedInteger('institutions_seen')->default(0);
            $table->unsignedInteger('events_seen')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->string('status')->default('pending')->index();

            // The classifier's opinion, kept separate from the decision. It is
            // advice: a reviewer may take it, change it, or ignore it, and the
            // record shows which happened.
            $table->foreignId('suggested_category_id')->nullable()->constrained('content_categories')->nullOnDelete();
            $table->string('suggested_action')->nullable();
            $table->string('suggested_severity')->nullable();
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->text('rationale')->nullable();
            $table->string('classifier_provider')->nullable();
            $table->string('classifier_model')->nullable();
            $table->timestamp('classified_at')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('content_category_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // The reviewer's working order: most widely seen, most recent first.
            $table->index(['status', 'learners_seen']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_reviews');
    }
};

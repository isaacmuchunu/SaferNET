<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('default_severity')->default('low');
            $table->boolean('is_high_risk')->default(false);
            $table->boolean('counts_toward_incidents')->default(true);
            $table->timestamps();
        });

        Schema::create('filtering_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('filtering_policies')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('learner_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('level')->index();
            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->timestamps();
            $table->index(['institution_id', 'learner_group_id', 'status']);
            $table->foreign(['learner_group_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('learner_groups');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE filtering_policies ADD CONSTRAINT filtering_policies_level_scope_check CHECK (
                (level = 'county' AND institution_id IS NULL AND learner_group_id IS NULL)
                OR (level = 'institution' AND institution_id IS NOT NULL AND learner_group_id IS NULL)
                OR (level = 'group' AND institution_id IS NOT NULL AND learner_group_id IS NOT NULL)
            )
        SQL);

        Schema::create('policy_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('filtering_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_category_id')->constrained()->restrictOnDelete();
            $table->string('action');
            $table->string('severity');
            $table->boolean('is_locked')->default(false);
            $table->boolean('counts_toward_incidents')->default(true);
            $table->unsignedInteger('threshold_count')->nullable();
            $table->unsignedInteger('threshold_window_minutes')->nullable();
            $table->boolean('notify_immediately')->default(false);
            $table->timestamps();
            $table->unique(['filtering_policy_id', 'content_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_rules');
        Schema::dropIfExists('filtering_policies');
        Schema::dropIfExists('content_categories');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subcounties', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcounty_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('nemis_code')->unique();
            $table->string('institution_type');
            $table->string('ownership');
            $table->string('status')->default('draft')->index();
            $table->string('physical_location');
            $table->string('hoi_name');
            $table->string('hoi_email');
            $table->string('hoi_phone');
            $table->unsignedInteger('learner_population')->default(0);
            $table->unsignedInteger('computing_devices_count')->default(0);
            $table->unsignedInteger('laboratories_count')->default(0);
            $table->string('connectivity_type')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['subcounty_id', 'status']);
            $table->unique(['id', 'subcounty_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('subcounty_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->nullable()->after('subcounty_id')->constrained()->restrictOnDelete();
            $table->foreign(['institution_id', 'subcounty_id'])
                ->references(['id', 'subcounty_id'])
                ->on('institutions');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_role_tenant_scope_check CHECK (
                (role = 'cde' AND subcounty_id IS NULL AND institution_id IS NULL)
                OR (role = 'scde' AND subcounty_id IS NOT NULL AND institution_id IS NULL)
                OR (role IN ('hoi', 'clm', 'service') AND subcounty_id IS NOT NULL AND institution_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['institution_id', 'subcounty_id']);
            $table->dropConstrainedForeignId('institution_id');
            $table->dropConstrainedForeignId('subcounty_id');
        });
        Schema::dropIfExists('institutions');
        Schema::dropIfExists('subcounties');
    }
};

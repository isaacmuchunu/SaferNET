<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('grade_level')->nullable();
            $table->string('academic_year')->nullable();
            $table->timestamps();
            $table->unique(['institution_id', 'name', 'academic_year']);
            $table->unique(['id', 'institution_id']);
        });

        Schema::create('learners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learner_group_id')->nullable();
            $table->string('learner_number');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('pin_hash')->nullable();
            $table->string('external_identity')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->unique(['institution_id', 'learner_number']);
            $table->unique(['id', 'institution_id']);
            $table->index(['institution_id', 'learner_group_id', 'status']);
            $table->foreign(['learner_group_id', 'institution_id'])
                ->references(['id', 'institution_id'])->on('learner_groups');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learners');
        Schema::dropIfExists('learner_groups');
    }
};

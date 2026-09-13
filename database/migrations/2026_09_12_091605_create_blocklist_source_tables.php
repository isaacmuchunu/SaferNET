<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocklist_sources', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('url');
            $table->foreignId('content_category_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->string('provenance');
            $table->boolean('is_enabled')->default(true)->index();

            // Written only by a synchronisation that actually completed, so the
            // console never reports domains from a source that failed to fetch.
            $table->unsignedInteger('domains_count')->default(0);
            $table->unsignedBigInteger('bytes_fetched')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('blocked_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocklist_source_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 253);
            $table->timestamps();

            $table->unique(['blocklist_source_id', 'domain']);
            $table->index('domain');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_domains');
        Schema::dropIfExists('blocklist_sources');
    }
};

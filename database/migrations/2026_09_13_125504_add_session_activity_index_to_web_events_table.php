<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The live classroom monitor asks for the newest event of each active session,
 * every few seconds. Without this index that question is answered by reading a
 * session's whole history, so the cost grows with how long the lesson has been
 * running rather than with the number of tiles on screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_events', function (Blueprint $table) {
            $table->index(['learner_session_id', 'occurred_at'], 'web_events_session_activity');
        });
    }

    public function down(): void
    {
        Schema::table('web_events', function (Blueprint $table) {
            $table->dropIndex('web_events_session_activity');
        });
    }
};

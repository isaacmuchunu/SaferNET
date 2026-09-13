<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every outbound safeguarding message, and what the provider said about it.
     *
     * A delivery row is written only after the provider answers, so "sent" means
     * the provider accepted the message rather than that SAFERNET tried to send
     * one. An alert is the thing a child's safety depends on; the email or SMS
     * is only the transport, and the transport must be auditable on its own.
     */
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel');                 // mail | sms
            $table->string('provider');                // smtp | log | africastalking
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('status')->index();         // sent | failed
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->string('event')->nullable()->index();
            $table->string('severity')->nullable();
            $table->nullableMorphs('notifiable_subject');
            $table->timestamps();

            $table->index(['channel', 'status', 'created_at']);
            $table->index(['institution_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};

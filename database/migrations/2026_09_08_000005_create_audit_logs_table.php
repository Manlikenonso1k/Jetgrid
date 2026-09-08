<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Kept even if the user is deleted — an audit trail that can be
            // erased by deleting an account is not an audit trail.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_email')->nullable();
            $table->string('user_role')->nullable();

            $table->string('command_key')->index();
            $table->text('command_string');
            $table->json('arguments')->nullable();

            $table->nullableMorphs('target');
            $table->string('target_label')->nullable();

            $table->boolean('dry_run')->default(true)->index();
            $table->string('driver')->default('fake');

            $table->integer('exit_code')->nullable();
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('outcome')->default('pending')->index();

            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->index();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

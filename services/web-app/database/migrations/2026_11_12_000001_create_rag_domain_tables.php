<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('allow_cloud_generation')->default(true);
            $table->timestamps();
        });

        Schema::create('collection_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('can_read')->default(true);
            $table->boolean('can_write')->default(false);
            $table->timestamps();
            $table->unique(['collection_id', 'user_id']);
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->json('tags')->nullable();
            $table->date('effective_date')->nullable();
            $table->unsignedInteger('active_version')->default(0);
            $table->unsignedInteger('latest_version')->default(1);
            $table->string('file_hash', 64)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('document_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('filename');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('file_hash', 64);
            $table->string('storage_path');
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('chunk_count')->default(0);
            $table->string('embedding_model')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'version']);
        });

        Schema::create('ingestion_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('document_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->string('status', 32)->default('queued');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('api_key_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('role', 16);
            $table->longText('content');
            $table->json('citations')->nullable();
            $table->string('mode', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('query_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('api_key_id')->nullable()->index();
            $table->uuid('conversation_id')->nullable()->index();
            $table->text('query_text')->nullable();
            $table->longText('answer_text')->nullable();
            $table->string('mode', 32);
            $table->boolean('grounded')->default(false);
            $table->string('model')->nullable();
            $table->json('chunks_used')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('estimated_cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('feedbacks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('query_log_id')->constrained('query_logs')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->smallInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('api_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('key_prefix', 16);
            $table->string('key_hash', 64)->unique();
            $table->json('scopes');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('prompt_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('version_label')->unique();
            $table->text('system_prompt')->nullable();
            $table->string('model_id');
            $table->float('temperature')->default(0.0);
            $table->integer('thinking_budget')->default(0);
            $table->integer('top_k')->default(20);
            $table->float('similarity_threshold')->default(0.35);
            $table->boolean('reranker_enabled')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('api_key_id')->nullable();
            $table->string('action', 64)->index();
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->string('key', 128)->primary();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status_code');
            $table->longText('response_body');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('prompt_versions');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('feedbacks');
        Schema::dropIfExists('query_logs');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('ingestion_jobs');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('collection_accesses');
        Schema::dropIfExists('collections');
    }
};

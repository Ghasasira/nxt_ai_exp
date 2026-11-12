<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('two_factor_secret')->nullable()->after('daily_query_quota');
            $table->boolean('two_factor_enabled')->default(false)->after('two_factor_secret');
            $table->boolean('is_active')->default(true)->after('two_factor_enabled');
        });

        Schema::create('golden_sets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('golden_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('golden_set_id')->constrained('golden_sets')->cascadeOnDelete();
            $table->text('question');
            $table->text('expected_answer')->nullable();
            $table->json('expected_document_ids')->nullable();
            $table->json('collection_ids')->nullable();
            $table->boolean('answerable')->default(true);
            $table->timestamps();
        });

        Schema::create('eval_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('golden_set_id')->constrained('golden_sets')->cascadeOnDelete();
            $table->foreignId('prompt_version_id')->nullable()->constrained('prompt_versions')->nullOnDelete();
            $table->string('model_id');
            $table->float('similarity_threshold')->default(0.35);
            $table->float('recall_at_5')->default(0.0);
            $table->float('grounded_rate')->default(0.0);
            $table->float('no_answer_accuracy')->default(0.0);
            $table->unsignedInteger('avg_latency_ms')->default(0);
            $table->json('results')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_runs');
        Schema::dropIfExists('golden_cases');
        Schema::dropIfExists('golden_sets');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_enabled', 'is_active']);
        });
    }
};

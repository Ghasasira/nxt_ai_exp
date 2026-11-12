<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionAccess;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\IngestionJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RagApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_requires_no_auth(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    public function test_api_key_scopes_and_revocation_are_enforced(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $createResp = $this->postJson('/api/v1/api-keys', [
            'name' => 'Search-Only Client',
            'scopes' => ['search'],
        ])->assertCreated();

        $token = $createResp->json('plaintext_token');
        $keyId = $createResp->json('api_key.id');

        // Clear session auth to test pure Bearer API key auth
        auth()->logout();

        $this->getJson('/api/v1/collections', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        // Revoke key via admin session and confirm 401
        $this->actingAs($admin)
            ->deleteJson("/api/v1/api-keys/{$keyId}")
            ->assertOk();

        auth()->logout();

        $this->postJson('/api/v1/search', ['query' => 'test'], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthorized');
    }

    public function test_document_upload_is_idempotent_and_callback_activates_version(): void
    {
        Http::fake([
            '*/internal/ingest' => Http::response(['status' => 'queued'], 202),
        ]);

        $manager = User::factory()->create(['role' => 'manager']);
        $collection = Collection::query()->create([
            'name' => 'HR Policies',
            'slug' => 'hr-policies',
            'allow_cloud_generation' => true,
        ]);
        CollectionAccess::query()->create([
            'collection_id' => $collection->id,
            'user_id' => $manager->id,
            'can_read' => true,
            'can_write' => true,
        ]);

        $this->actingAs($manager);
        $file = UploadedFile::fake()->createWithContent('policy.md', "# Leave Policy\nEmployees get 21 days.");

        $resp1 = $this->withHeader('Idempotency-Key', 'upload-key-1')
            ->post('/api/v1/documents', [
                'collection_id' => $collection->id,
                'title' => 'Leave Policy',
                'file' => $file,
            ]);
        $resp1->assertStatus(202);
        $jobId = $resp1->json('ingestion_job.id');
        $docId = $resp1->json('document.id');

        // Replay with identical Idempotency-Key must return cached response without creating a 2nd document
        $resp2 = $this->withHeader('Idempotency-Key', 'upload-key-1')
            ->post('/api/v1/documents', [
                'collection_id' => $collection->id,
                'title' => 'Leave Policy',
                'file' => $file,
            ]);
        $resp2->assertStatus(202)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, Document::query()->count());

        // Unsigned callback must be rejected
        $badPayload = json_encode(['job_id' => $jobId, 'status' => 'indexed', 'version' => 1]);
        $this->call('POST', '/api/internal/ingestion-callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CALLBACK_SIGNATURE' => 'sha256=invalid',
        ], $badPayload)->assertStatus(401);

        // Signed callback must promote active_version = 1
        $goodPayload = json_encode([
            'job_id' => $jobId,
            'document_id' => $docId,
            'version' => 1,
            'status' => 'indexed',
            'chunk_count' => 4,
            'embedding_model' => 'nomic-embed-text',
        ]);
        $sig = 'sha256='.hash_hmac('sha256', $goodPayload, (string) config('rag.callback_secret'));
        $this->call('POST', '/api/internal/ingestion-callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CALLBACK_SIGNATURE' => $sig,
        ], $goodPayload)->assertOk();

        $this->assertSame(1, Document::query()->find($docId)->active_version);
        $this->assertSame('indexed', IngestionJob::query()->find($jobId)->status);
        $this->assertSame(4, DocumentVersion::query()->where('document_id', $docId)->first()->chunk_count);
    }

    public function test_chat_respects_collection_cloud_opt_out_and_records_feedback(): void
    {
        $requestUuid = (string) Str::uuid();
        Http::fake([
            '*/internal/chat' => Http::response([
                'request_id' => $requestUuid,
                'answer' => 'Cloud generation is unavailable or disabled for this collection.',
                'grounded' => true,
                'mode' => 'retrieval_only',
                'model' => 'retrieval_only',
                'citations' => [
                    ['document_id' => 'd1', 'title' => 'Secret', 'chunk_id' => 'c1', 'snippet' => 'Confidential'],
                ],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'latency_ms' => 42],
            ], 200),
        ]);

        $reader = User::factory()->create(['role' => 'reader']);
        $restricted = Collection::query()->create([
            'name' => 'Board Minutes',
            'slug' => 'board-minutes',
            'allow_cloud_generation' => false,
        ]);
        CollectionAccess::query()->create([
            'collection_id' => $restricted->id,
            'user_id' => $reader->id,
            'can_read' => true,
            'can_write' => false,
        ]);

        $this->actingAs($reader);

        // Reader cannot upload documents (403)
        $this->postJson('/api/v1/documents', ['collection_id' => $restricted->id])
            ->assertStatus(403);

        // Chat forwards allow_cloud_generation = false to rag-core
        $chatResp = $this->postJson('/api/v1/chat', [
            'query' => 'What did the board decide?',
            'collection_ids' => [$restricted->id],
        ])->assertOk();

        $chatResp->assertJsonPath('mode', 'retrieval_only');
        Http::assertSent(fn ($req) => $req['allow_cloud_generation'] === false && $req['collection_ids'] === [$restricted->id]);

        // Feedback against returned request_id succeeds
        $this->postJson('/api/v1/feedback', [
            'request_id' => $requestUuid,
            'rating' => 1,
            'comment' => 'Accurate passage',
        ])->assertCreated();
    }
}

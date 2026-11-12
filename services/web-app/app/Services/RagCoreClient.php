<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class RagCoreClient
{
    protected function client(int $timeoutSeconds = 30): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('rag.core_url'), '/'))
            ->timeout($timeoutSeconds)
            ->withHeaders([
                'X-Internal-Secret' => (string) config('rag.internal_secret'),
                'Accept' => 'application/json',
            ]);
    }

    public function health(): Response
    {
        return $this->client(5)->get('/health');
    }

    public function ready(): Response
    {
        return $this->client(8)->get('/ready');
    }

    public function enqueueIngest(array $payload): Response
    {
        return $this->client(15)->post('/internal/ingest', $payload);
    }

    public function deleteDocument(string $documentId): Response
    {
        return $this->client(15)->delete("/internal/documents/{$documentId}");
    }

    public function getDocumentChunks(string $documentId, ?int $version = null): Response
    {
        $query = $version !== null ? ['version' => $version] : [];

        return $this->client(15)->get("/internal/documents/{$documentId}/chunks", $query);
    }

    public function search(array $payload): Response
    {
        return $this->client(20)->post('/internal/search', $payload);
    }

    public function chat(array $payload): Response
    {
        return $this->client(45)->post('/internal/chat', $payload);
    }

    /**
     * Stream raw SSE response body from rag-core /internal/chat/stream.
     */
    public function streamChat(array $payload): \Psr\Http\Message\StreamInterface
    {
        $response = $this->client(60)
            ->withOptions(['stream' => true])
            ->post('/internal/chat/stream', $payload);

        $response->throw();

        return $response->toPsrResponse()->getBody();
    }
}

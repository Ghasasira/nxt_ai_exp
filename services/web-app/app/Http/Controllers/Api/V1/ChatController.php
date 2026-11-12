<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Feedback;
use App\Models\Message;
use App\Models\QueryLog;
use App\Models\Setting;
use App\Services\AccessResolver;
use App\Services\RagCoreClient;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function search(Request $request, AccessResolver $access, RagCoreClient $rag): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
            'collection_ids' => ['nullable', 'array'],
            'collection_ids.*' => ['uuid'],
            'top_k' => ['nullable', 'integer', 'min:1', 'max:50'],
            'filters' => ['nullable', 'array'],
            'filters.tags' => ['nullable', 'array'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date'],
        ]);

        $permitted = $access->readableCollectionIds($request->user(), $validated['collection_ids'] ?? null);
        if ($permitted === []) {
            return response()->json(['count' => 0, 'results' => []]);
        }

        $payload = [
            'query' => $validated['query'],
            'collection_ids' => $permitted,
            'top_k' => (int) ($validated['top_k'] ?? 10),
            'tags' => $validated['filters']['tags'] ?? null,
            'date_from_ts' => isset($validated['filters']['date_from']) ? strtotime($validated['filters']['date_from']) : null,
            'date_to_ts' => isset($validated['filters']['date_to']) ? strtotime($validated['filters']['date_to']) : null,
        ];

        $resp = $rag->search($payload);
        if (! $resp->successful()) {
            return ApiError::response($request, 'upstream_error', 'Search service failed.', 502);
        }

        return response()->json($resp->json());
    }

    public function chat(Request $request, AccessResolver $access, RagCoreClient $rag): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'uuid'],
            'collection_ids' => ['nullable', 'array'],
            'collection_ids.*' => ['uuid'],
            'top_k' => ['nullable', 'integer', 'min:1', 'max:50'],
            'stream' => ['nullable', 'boolean'],
            'filters' => ['nullable', 'array'],
            'filters.tags' => ['nullable', 'array'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $apiKeyId = $request->attributes->get('api_key_id');
        $permitted = $access->readableCollectionIds($user, $validated['collection_ids'] ?? null);

        $conversation = null;
        if (! empty($validated['conversation_id'])) {
            $conversation = Conversation::query()
                ->where('id', $validated['conversation_id'])
                ->where('user_id', $user->id)
                ->first();
            if (! $conversation) {
                return ApiError::response($request, 'not_found', 'Conversation not found.', 404);
            }
        } else {
            $conversation = Conversation::query()->create([
                'user_id' => $user->id,
                'api_key_id' => $apiKeyId,
                'title' => Str::limit($validated['query'], 80),
            ]);
        }

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $validated['query'],
        ]);

        if ($permitted === []) {
            $requestId = (string) Str::uuid();
            $noAccessMsg = 'You do not have access to any collections matching this request.';
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $noAccessMsg,
                'citations' => [],
                'mode' => 'no_answer',
            ]);

            return response()->json([
                'request_id' => $requestId,
                'answer' => $noAccessMsg,
                'grounded' => false,
                'mode' => 'no_answer',
                'citations' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'latency_ms' => 0],
                'conversation_id' => $conversation->id,
            ]);
        }

        $history = $conversation->messages()
            ->orderByDesc('created_at')
            ->limit(6)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn (Message $m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        $settingsRow = Setting::query()->find('rag_runtime');
        $runtime = $settingsRow?->value ?? [];
        $modelId = (string) ($runtime['model_id'] ?? config('rag.default_model'));
        $maxTopK = (int) ($runtime['top_k'] ?? config('rag.default_top_k'));
        $threshold = (float) ($runtime['similarity_threshold'] ?? config('rag.default_threshold'));
        $allowCloud = $access->shouldUseCloudGeneration($user, $permitted);

        $ragPayload = [
            'query' => $validated['query'],
            'collection_ids' => $permitted,
            'top_k' => min((int) ($validated['top_k'] ?? $maxTopK), $maxTopK),
            'tags' => $validated['filters']['tags'] ?? null,
            'date_from_ts' => isset($validated['filters']['date_from']) ? strtotime($validated['filters']['date_from']) : null,
            'date_to_ts' => isset($validated['filters']['date_to']) ? strtotime($validated['filters']['date_to']) : null,
            'allow_cloud_generation' => $allowCloud,
            'similarity_threshold' => $threshold,
            'model_override' => $modelId,
            'history' => $history,
        ];

        if (! empty($validated['stream'])) {
            return $this->handleStreamChat($request, $rag, $ragPayload, $conversation, $user->id, $apiKeyId, $validated['query']);
        }

        $resp = $rag->chat($ragPayload);
        if (! $resp->successful()) {
            return ApiError::response($request, 'upstream_error', 'RAG core generation failed.', 502);
        }

        $data = $resp->json();
        $data['conversation_id'] = $conversation->id;
        $this->persistAssistantResult($conversation, $user->id, $apiKeyId, $validated['query'], $data);

        return response()->json($data);
    }

    protected function handleStreamChat(
        Request $request,
        RagCoreClient $rag,
        array $ragPayload,
        Conversation $conversation,
        int $userId,
        ?string $apiKeyId,
        string $userQuery,
    ): StreamedResponse {
        $stream = $rag->streamChat($ragPayload);

        return response()->stream(function () use ($stream, $conversation, $userId, $apiKeyId, $userQuery): void {
            $buffer = '';
            while (! $stream->eof()) {
                $chunk = $stream->read(1024);
                if ($chunk === '') {
                    continue;
                }
                $buffer .= $chunk;
                echo $chunk;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            if (preg_match('/event:\s*done\s*\ndata:\s*(\{.*\})/s', $buffer, $matches)) {
                $doneData = json_decode($matches[1], true);
                if (is_array($doneData)) {
                    $doneData['conversation_id'] = $conversation->id;
                    $this->persistAssistantResult($conversation, $userId, $apiKeyId, $userQuery, $doneData);
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    protected function persistAssistantResult(
        Conversation $conversation,
        int $userId,
        ?string $apiKeyId,
        string $userQuery,
        array $data,
    ): void {
        $usage = $data['usage'] ?? [];
        $inTokens = (int) ($usage['input_tokens'] ?? 0);
        $outTokens = (int) ($usage['output_tokens'] ?? 0);
        $model = (string) ($data['model'] ?? config('rag.default_model'));
        $rates = config("rag.pricing_per_1m.{$model}", ['input' => 0.25, 'output' => 1.50]);
        $cost = ($inTokens / 1_000_000) * $rates['input'] + ($outTokens / 1_000_000) * $rates['output'];

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => (string) ($data['answer'] ?? ''),
            'citations' => $data['citations'] ?? [],
            'mode' => (string) ($data['mode'] ?? 'generated'),
        ]);

        QueryLog::query()->create([
            'request_id' => (string) ($data['request_id'] ?? Str::uuid()),
            'user_id' => $userId,
            'api_key_id' => $apiKeyId,
            'conversation_id' => $conversation->id,
            'query_text' => $userQuery,
            'answer_text' => (string) ($data['answer'] ?? ''),
            'mode' => (string) ($data['mode'] ?? 'generated'),
            'grounded' => (bool) ($data['grounded'] ?? false),
            'model' => $model,
            'chunks_used' => $data['citations'] ?? [],
            'input_tokens' => $inTokens,
            'output_tokens' => $outTokens,
            'estimated_cost_usd' => $cost,
            'latency_ms' => (int) ($usage['latency_ms'] ?? 0),
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $items = Conversation::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json($items);
    }

    public function showConversation(Request $request, string $id): JsonResponse
    {
        $conv = Conversation::query()
            ->with('messages')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $conv) {
            return ApiError::response($request, 'not_found', 'Conversation not found.', 404);
        }

        return response()->json($conv);
    }

    public function deleteConversation(Request $request, string $id): JsonResponse
    {
        $deleted = Conversation::query()
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->delete();

        if (! $deleted) {
            return ApiError::response($request, 'not_found', 'Conversation not found.', 404);
        }

        return response()->json(['deleted' => true]);
    }

    public function feedback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
            'rating' => ['required', 'integer', 'in:-1,1'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $log = QueryLog::query()->where('request_id', $validated['request_id'])->first();
        if (! $log) {
            return ApiError::response($request, 'not_found', 'Query request_id not found.', 404);
        }

        $fb = Feedback::query()->create([
            'query_log_id' => $log->id,
            'user_id' => $request->user()->id,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
        ]);

        return response()->json($fb, 201);
    }
}

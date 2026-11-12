<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\CollectionAccess;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\EvalRun;
use App\Models\GoldenCase;
use App\Models\GoldenSet;
use App\Models\IngestionJob;
use App\Models\PromptVersion;
use App\Models\QueryLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccessResolver;
use App\Services\AuditLogger;
use App\Services\RagCoreClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class WorkspaceController extends Controller
{
    public function chat(Request $request, AccessResolver $access): Response
    {
        $user = $request->user();
        $permitted = $access->readableCollectionIds($user);
        $collections = Collection::query()
            ->whereIn('id', $permitted)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'allow_cloud_generation']);

        $conversations = Conversation::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get(['id', 'title', 'updated_at']);

        return Inertia::render('Chat/Index', [
            'collections' => $collections,
            'conversations' => $conversations,
        ]);
    }

    public function dashboard(Request $request): Response
    {
        $since = now()->subDays(30)->startOfDay();
        $todayStart = now()->startOfDay();

        $totalQueries = QueryLog::query()->where('created_at', '>=', $since)->count();
        $todayQueries = QueryLog::query()->where('created_at', '>=', $todayStart)->count();
        $noAnswerCount = QueryLog::query()->where('created_at', '>=', $since)->where('mode', 'no_answer')->count();
        $avgLatency = (int) round((float) QueryLog::query()->where('created_at', '>=', $since)->avg('latency_ms'));
        $monthCost = (float) QueryLog::query()->where('created_at', '>=', now()->startOfMonth())->sum('estimated_cost_usd');
        $monthTokens = (int) QueryLog::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) as total')
            ->value('total');

        $capSetting = Setting::query()->find('monthly_spend_cap_usd');
        $spendCap = (float) ($capSetting?->value['amount'] ?? config('rag.monthly_spend_cap_usd'));

        $recentFailures = IngestionJob::query()
            ->with('document:id,title')
            ->where('status', 'failed')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        // FR-EVAL-05: Surface frequently unanswered questions as documentation gaps
        $docGaps = QueryLog::query()
            ->where('mode', 'no_answer')
            ->whereNotNull('query_text')
            ->select('query_text', DB::raw('COUNT(*) as occurrences'), DB::raw('MAX(created_at) as last_asked'))
            ->groupBy('query_text')
            ->orderByDesc('occurrences')
            ->limit(10)
            ->get();

        return Inertia::render('Dashboard/Index', [
            'stats' => [
                'total_queries_30d' => $totalQueries,
                'today_queries' => $todayQueries,
                'no_answer_rate' => $totalQueries > 0 ? round(($noAnswerCount / $totalQueries) * 100, 1) : 0.0,
                'avg_latency_ms' => $avgLatency,
                'month_cost_usd' => round($monthCost, 4),
                'month_tokens' => $monthTokens,
                'spend_cap_usd' => $spendCap,
                'budget_pct' => $spendCap > 0 ? round(($monthCost / $spendCap) * 100, 1) : 0.0,
            ],
            'recentFailures' => $recentFailures,
            'docGaps' => $docGaps,
        ]);
    }

    public function documents(Request $request, AccessResolver $access): Response
    {
        $permitted = $access->readableCollectionIds($request->user());
        $collections = Collection::query()->whereIn('id', $permitted)->orderBy('name')->get();

        $query = Document::query()
            ->with(['collection:id,name', 'versions' => fn ($q) => $q->limit(1)])
            ->whereIn('collection_id', $permitted);

        if ($request->filled('collection_id')) {
            $query->where('collection_id', $request->string('collection_id')->toString());
        }
        if ($request->filled('q')) {
            $term = '%'.$request->string('q')->toString().'%';
            $query->where('title', 'like', $term);
        }

        return Inertia::render('Documents/Index', [
            'documents' => $query->orderByDesc('updated_at')->paginate(20)->withQueryString(),
            'collections' => $collections,
            'filters' => $request->only(['collection_id', 'q']),
        ]);
    }

    public function documentShow(Request $request, string $id, AccessResolver $access, RagCoreClient $rag): Response
    {
        $doc = Document::query()->with(['collection', 'versions', 'ingestionJobs'])->findOrFail($id);
        if ($access->readableCollectionIds($request->user(), [$doc->collection_id]) === []) {
            abort(403);
        }

        $chunks = [];
        try {
            $resp = $rag->getDocumentChunks($doc->id);
            if ($resp->successful()) {
                $chunks = $resp->json('chunks', []);
            }
        } catch (Throwable) {
            $chunks = [];
        }

        return Inertia::render('Documents/Show', [
            'document' => $doc,
            'chunks' => $chunks,
        ]);
    }

    public function collections(Request $request, AccessResolver $access): Response
    {
        $permitted = $access->readableCollectionIds($request->user());
        $collections = Collection::query()
            ->with(['accesses.user:id,name,email,role'])
            ->withCount('documents')
            ->whereIn('id', $permitted)
            ->orderBy('name')
            ->get();

        $users = User::query()->orderBy('name')->get(['id', 'name', 'email', 'role']);

        return Inertia::render('Collections/Index', [
            'collections' => $collections,
            'users' => $users,
        ]);
    }

    public function updateCollectionAccess(Request $request, string $id, AccessResolver $access, AuditLogger $audit): RedirectResponse
    {
        if (! $access->canWriteCollection($request->user(), $id)) {
            abort(403);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'can_read' => ['required', 'boolean'],
            'can_write' => ['required', 'boolean'],
        ]);

        CollectionAccess::query()->updateOrCreate(
            ['collection_id' => $id, 'user_id' => $validated['user_id']],
            ['can_read' => $validated['can_read'], 'can_write' => $validated['can_write']]
        );
        $audit->record($request, 'collection.access_update', 'collection', $id, $validated);

        return back()->with('success', 'Collection access updated.');
    }

    public function ingestionQueue(Request $request, AccessResolver $access): Response
    {
        $permitted = $access->readableCollectionIds($request->user());
        $jobs = IngestionJob::query()
            ->whereHas('document', fn ($q) => $q->whereIn('collection_id', $permitted))
            ->with(['document:id,title,collection_id', 'documentVersion:id,version,filename,chunk_count'])
            ->orderByDesc('created_at')
            ->paginate(25);

        return Inertia::render('Ingestion/Index', ['jobs' => $jobs]);
    }

    public function retryIngestion(Request $request, string $id, AccessResolver $access, RagCoreClient $rag, AuditLogger $audit): RedirectResponse
    {
        $job = IngestionJob::query()->with(['document', 'documentVersion'])->findOrFail($id);
        if (! $access->canWriteCollection($request->user(), $job->document->collection_id)) {
            abort(403);
        }

        $job->update(['status' => 'queued', 'error_message' => null]);
        $job->documentVersion->update(['status' => 'queued']);

        $rag->enqueueIngest([
            'job_id' => $job->id,
            'document_id' => $job->document->id,
            'collection_id' => $job->document->collection_id,
            'title' => $job->document->title,
            'version' => $job->documentVersion->version,
            'filename' => $job->documentVersion->filename,
            'storage_path' => $job->documentVersion->storage_path,
            'tags' => $job->document->tags ?? [],
            'effective_ts' => $job->document->effective_date?->timestamp,
        ])->throw();

        $audit->record($request, 'ingestion.retry', 'ingestion_job', $job->id);

        return back()->with('success', 'Ingestion job re-queued.');
    }

    public function playground(Request $request, AccessResolver $access): Response
    {
        $permitted = $access->readableCollectionIds($request->user());
        $collections = Collection::query()->whereIn('id', $permitted)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Playground/Index', ['collections' => $collections]);
    }

    public function queryLogs(Request $request): Response
    {
        $query = QueryLog::query()->with('feedbacks');
        if ($request->string('filter')->toString() === 'unanswered') {
            $query->where('mode', 'no_answer');
        } elseif ($request->string('filter')->toString() === 'low_rated') {
            $query->whereHas('feedbacks', fn ($q) => $q->where('rating', -1));
        }

        $goldenSets = GoldenSet::query()->orderBy('name')->get(['id', 'name']);

        return Inertia::render('QueryLogs/Index', [
            'logs' => $query->orderByDesc('created_at')->paginate(25)->withQueryString(),
            'filter' => $request->string('filter')->toString(),
            'goldenSets' => $goldenSets,
        ]);
    }

    public function evaluation(Request $request): Response
    {
        $sets = GoldenSet::query()->with('cases')->withCount('cases')->orderBy('name')->get();
        $runs = EvalRun::query()->with('goldenSet:id,name')->orderByDesc('created_at')->limit(20)->get();
        $collections = Collection::query()->orderBy('name')->get(['id', 'name']);
        $documents = Document::query()->orderBy('title')->get(['id', 'title']);

        return Inertia::render('Evaluation/Index', [
            'goldenSets' => $sets,
            'evalRuns' => $runs,
            'collections' => $collections,
            'documents' => $documents,
        ]);
    }

    public function storeGoldenSet(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        GoldenSet::query()->create([...$validated, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Golden set created.');
    }

    public function storeGoldenCase(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'golden_set_id' => ['required', 'uuid', 'exists:golden_sets,id'],
            'question' => ['required', 'string', 'max:2000'],
            'expected_answer' => ['nullable', 'string'],
            'expected_document_ids' => ['nullable', 'array'],
            'collection_ids' => ['nullable', 'array'],
            'answerable' => ['boolean'],
        ]);

        GoldenCase::query()->create([
            'golden_set_id' => $validated['golden_set_id'],
            'question' => $validated['question'],
            'expected_answer' => $validated['expected_answer'] ?? null,
            'expected_document_ids' => $validated['expected_document_ids'] ?? [],
            'collection_ids' => $validated['collection_ids'] ?? [],
            'answerable' => $validated['answerable'] ?? true,
        ]);

        return back()->with('success', 'Case added to golden set.');
    }

    public function runEvaluation(Request $request, string $goldenSetId, AccessResolver $access, RagCoreClient $rag): RedirectResponse
    {
        $set = GoldenSet::query()->with('cases')->findOrFail($goldenSetId);
        if ($set->cases->isEmpty()) {
            return back()->with('error', 'Add at least one test case before running evaluation.');
        }

        $runtime = Setting::query()->find('rag_runtime')?->value ?? [];
        $modelId = (string) ($runtime['model_id'] ?? config('rag.default_model'));
        $threshold = (float) ($runtime['similarity_threshold'] ?? config('rag.default_threshold'));
        $topK = (int) ($runtime['top_k'] ?? config('rag.default_top_k'));
        $allPermitted = $access->readableCollectionIds($request->user());

        $answerableTotal = 0;
        $recallHits = 0;
        $groundedHits = 0;
        $unanswerableTotal = 0;
        $noAnswerHits = 0;
        $latencies = [];
        $caseResults = [];

        foreach ($set->cases as $case) {
            $cols = ! empty($case->collection_ids) ? $case->collection_ids : $allPermitted;
            $allowCloud = $access->shouldUseCloudGeneration($request->user(), $cols);

            $searchResp = $rag->search(['query' => $case->question, 'collection_ids' => $cols, 'top_k' => 5]);
            $top5Docs = collect($searchResp->successful() ? $searchResp->json('results', []) : [])
                ->pluck('document_id')
                ->all();

            $askResp = $rag->chat([
                'query' => $case->question,
                'collection_ids' => $cols,
                'top_k' => $topK,
                'allow_cloud_generation' => $allowCloud,
                'similarity_threshold' => $threshold,
                'model_override' => $modelId,
            ]);

            $data = $askResp->successful() ? $askResp->json() : ['mode' => 'error', 'grounded' => false, 'usage' => ['latency_ms' => 0]];
            $lat = (int) ($data['usage']['latency_ms'] ?? 0);
            $latencies[] = $lat;

            if ($case->answerable) {
                $answerableTotal++;
                $expectedDocs = $case->expected_document_ids ?? [];
                $recalled = $expectedDocs === [] ? count($top5Docs) > 0 : count(array_intersect($expectedDocs, $top5Docs)) > 0;
                if ($recalled) {
                    $recallHits++;
                }
                if (! empty($data['grounded'])) {
                    $groundedHits++;
                }
            } else {
                $unanswerableTotal++;
                if (($data['mode'] ?? '') === 'no_answer') {
                    $noAnswerHits++;
                }
            }

            $caseResults[] = [
                'case_id' => $case->id,
                'question' => $case->question,
                'mode' => $data['mode'] ?? 'error',
                'grounded' => (bool) ($data['grounded'] ?? false),
                'latency_ms' => $lat,
            ];
        }

        $activePrompt = PromptVersion::query()->where('is_active', true)->first();

        EvalRun::query()->create([
            'golden_set_id' => $set->id,
            'prompt_version_id' => $activePrompt?->id,
            'model_id' => $modelId,
            'similarity_threshold' => $threshold,
            'recall_at_5' => $answerableTotal > 0 ? round($recallHits / $answerableTotal, 3) : 0.0,
            'grounded_rate' => $answerableTotal > 0 ? round($groundedHits / $answerableTotal, 3) : 0.0,
            'no_answer_accuracy' => $unanswerableTotal > 0 ? round($noAnswerHits / $unanswerableTotal, 3) : 1.0,
            'avg_latency_ms' => count($latencies) > 0 ? (int) round(array_sum($latencies) / count($latencies)) : 0,
            'results' => $caseResults,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Golden-set evaluation completed.');
    }
}

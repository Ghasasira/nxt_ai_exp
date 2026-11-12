<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\RagCoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(RagCoreClient $rag): JsonResponse
    {
        $dbOk = false;
        $ragOk = false;

        try {
            DB::select('SELECT 1');
            $dbOk = true;
        } catch (Throwable) {
            $dbOk = false;
        }

        try {
            $ragOk = $rag->ready()->successful();
        } catch (Throwable) {
            $ragOk = false;
        }

        if (! $dbOk || ! $ragOk) {
            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ready']);
    }
}

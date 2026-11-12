<?php

return [
    'core_url' => env('RAG_CORE_URL', 'http://rag-core:8000'),
    'internal_secret' => env('RAG_INTERNAL_SECRET', 'dev-internal-secret'),
    'callback_secret' => env('RAG_CALLBACK_SECRET', 'dev-callback-secret'),
    'uploads_dir' => env('RAG_UPLOADS_DIR', storage_path('app/uploads')),
    'backup_status_file' => env('RAG_BACKUP_STATUS_FILE', '/data/backups/last_backup.json'),
    'max_upload_mb' => (int) env('RAG_MAX_UPLOAD_MB', 50),
    'default_model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),
    'default_top_k' => (int) env('RETRIEVAL_TOP_K', 20),
    'default_threshold' => (float) env('SIMILARITY_THRESHOLD', 0.35),
    'monthly_spend_cap_usd' => (float) env('MONTHLY_SPEND_CAP_USD', 200.0),
    'pricing_per_1m' => [
        'gemini-3.1-flash-lite' => ['input' => 0.25, 'output' => 1.50],
        'gemini-3.5-flash-lite' => ['input' => 0.30, 'output' => 2.50],
    ],
];

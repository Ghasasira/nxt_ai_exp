<?php

namespace Database\Seeders;

use App\Models\Collection;
use App\Models\PromptVersion;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->firstOrCreate(
            ['email' => env('INITIAL_ADMIN_EMAIL', 'admin@nxt.local')],
            [
                'name' => 'System Admin',
                'password' => env('INITIAL_ADMIN_PASSWORD', 'AdminPass123!'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        Collection::query()->firstOrCreate(
            ['slug' => 'general'],
            [
                'name' => 'General Knowledge',
                'description' => 'Default organisation knowledge base collection.',
                'allow_cloud_generation' => true,
            ]
        );

        $runtime = [
            'model_id' => config('rag.default_model'),
            'temperature' => 0.0,
            'thinking_budget' => 0,
            'top_k' => config('rag.default_top_k'),
            'similarity_threshold' => config('rag.default_threshold'),
            'reranker_enabled' => false,
        ];

        Setting::query()->firstOrCreate(
            ['key' => 'rag_runtime'],
            ['value' => $runtime, 'updated_by' => $admin->id]
        );

        PromptVersion::query()->firstOrCreate(
            ['version_label' => 'v-initial'],
            [
                'system_prompt' => 'default',
                ...$runtime,
                'created_by' => $admin->id,
                'is_active' => true,
            ]
        );
    }
}

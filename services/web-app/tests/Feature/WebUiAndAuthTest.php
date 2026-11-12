<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\EvalRun;
use App\Models\GoldenSet;
use App\Models\PromptVersion;
use App\Models\Setting;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebUiAndAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_gates_protect_management_and_admin_pages(): void
    {
        $reader = User::factory()->create(['role' => 'reader']);
        $manager = User::factory()->create(['role' => 'manager']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($reader)->get('/chat')->assertOk();
        $this->actingAs($reader)->get('/dashboard')->assertStatus(403);
        $this->actingAs($reader)->get('/admin/settings')->assertStatus(403);

        $this->actingAs($manager)->get('/dashboard')->assertOk();
        $this->actingAs($manager)->get('/documents')->assertOk();
        $this->actingAs($manager)->get('/admin/settings')->assertStatus(403);

        $this->actingAs($admin)->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Settings'));
        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->actingAs($admin)->get('/admin/api-keys')->assertOk();
        $this->actingAs($admin)->get('/admin/audit-log')->assertOk();
    }

    public function test_login_with_totp_two_factor_flow(): void
    {
        $secret = Totp::generateSecret();
        $user = User::factory()->create([
            'email' => 'admin2fa@nxt.local',
            'password' => 'SuperSecret123!',
            'role' => 'admin',
            'two_factor_secret' => $secret,
            'two_factor_enabled' => true,
        ]);

        // Step 1: Password check puts user in 2FA pending state
        $this->post('/login', [
            'email' => 'admin2fa@nxt.local',
            'password' => 'SuperSecret123!',
        ])->assertRedirect('/login');

        $this->assertGuest();

        // Step 2: Valid 6-digit TOTP completes authentication
        $code = Totp::hotp($secret, (int) floor(time() / 30));
        $this->post('/login', ['otp_code' => $code])->assertRedirect('/chat');
        $this->assertAuthenticatedAs($user);
    }

    public function test_settings_update_and_prompt_version_rollback(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $this->put('/admin/settings', [
            'model_id' => 'gemini-3.1-flash-lite',
            'temperature' => 0.0,
            'thinking_budget' => 0,
            'top_k' => 15,
            'similarity_threshold' => 0.42,
            'reranker_enabled' => false,
            'monthly_spend_cap_usd' => 250,
            'retention_days' => 60,
        ])->assertRedirect();

        $v1 = PromptVersion::query()->firstOrFail();

        $this->put('/admin/settings', [
            'model_id' => 'gemini-3.5-flash-lite',
            'temperature' => 0.0,
            'thinking_budget' => 0,
            'top_k' => 25,
            'similarity_threshold' => 0.30,
            'reranker_enabled' => true,
            'monthly_spend_cap_usd' => 300,
            'retention_days' => 60,
        ])->assertRedirect();

        $this->assertSame('gemini-3.5-flash-lite', Setting::query()->find('rag_runtime')->value['model_id']);

        $this->post("/admin/settings/rollback/{$v1->id}")->assertRedirect();
        $this->assertSame('gemini-3.1-flash-lite', Setting::query()->find('rag_runtime')->value['model_id']);
    }

    public function test_golden_set_evaluation_run_and_seeder(): void
    {
        $this->seed();
        $this->assertDatabaseHas('users', ['role' => 'admin']);
        $this->assertDatabaseHas('collections', ['slug' => 'general']);

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $col = Collection::query()->firstOrFail();

        Http::fake([
            '*/internal/search' => Http::response([
                'count' => 1,
                'results' => [['document_id' => 'doc-1', 'score' => 0.85]],
            ], 200),
            '*/internal/chat' => Http::response([
                'mode' => 'generated',
                'grounded' => true,
                'usage' => ['latency_ms' => 120],
            ], 200),
        ]);

        $this->actingAs($admin);
        $this->post('/evaluation/sets', ['name' => 'Core Policy Set'])->assertRedirect();
        $set = GoldenSet::query()->firstOrFail();

        $this->post('/evaluation/cases', [
            'golden_set_id' => $set->id,
            'question' => 'What is the retention window?',
            'expected_document_ids' => ['doc-1'],
            'collection_ids' => [$col->id],
            'answerable' => true,
        ])->assertRedirect();

        $this->post("/evaluation/sets/{$set->id}/run")->assertRedirect();

        $run = EvalRun::query()->firstOrFail();
        $this->assertSame(1.0, $run->recall_at_5);
        $this->assertSame(1.0, $run->grounded_rate);
    }

    public function test_privacy_notice_and_request_id_header(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertHeader('X-Request-Id')
            ->assertInertia(fn (Assert $page) => $page->component('Privacy'));
    }
}

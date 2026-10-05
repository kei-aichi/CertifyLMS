<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 10 Route の認証・認可と Binding を検証する。作成・更新の成功は WriteTest で検証する。
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function endpoints(MeetingPack $plan): array
    {
        return [
            ['GET', 'index', []], ['GET', 'create', []], ['POST', 'store', []],
            ['GET', 'show', [$plan]], ['GET', 'edit', [$plan]],
            ['PATCH', 'update', [$plan]], ['DELETE', 'destroy', [$plan]],
            ['POST', 'publish', [$plan]], ['POST', 'archive', [$plan]],
            ['POST', 'unarchive', [$plan]],
        ];
    }

    private function payload(): array
    {
        return ['name' => '面談パック', 'meeting_count' => 5, 'price' => 3000];
    }

    public function test_guests_are_rejected_on_every_endpoint(): void
    {
        $plan = MeetingPack::factory()->create();
        foreach ($this->endpoints($plan) as [$method, $name, $parameters]) {
            $url = route('admin.meeting-packs.'.$name, $parameters);
            $this->call($method, $url, $this->payload())->assertRedirect(route('login'));
            $this->json($method, $url, $this->payload())->assertUnauthorized();
        }
    }

    public function test_students_and_coaches_are_forbidden_on_every_endpoint(): void
    {
        $plan = MeetingPack::factory()->create();
        foreach ([User::factory()->student()->create(), User::factory()->coach()->create()] as $user) {
            $this->actingAs($user);
            foreach ($this->endpoints($plan) as [$method, $name, $parameters]) {
                $this->json($method, route('admin.meeting-packs.'.$name, $parameters), $this->payload())->assertForbidden();
            }
        }
    }

    public function test_admin_passes_authorization_without_executing_business_operations(): void
    {
        $plan = MeetingPack::factory()->create();
        $before = $plan->fresh()->getRawOriginal();
        $this->actingAs(User::factory()->admin()->create());
        foreach ($this->endpoints($plan) as [$method, $name, $parameters]) {
            if (in_array($name, ['store', 'update'], true)) {
                continue;
            }
            // 削除・状態変更は未接続のまま、データを変更しない。
            $expected = in_array($name, ['index', 'show', 'create', 'edit'], true) ? 200 : 501;
            $this->json($method, route('admin.meeting-packs.'.$name, $parameters), $this->payload())->assertStatus($expected);
        }
        $this->assertDatabaseCount('meeting_packs', 1);
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
    }

    public function test_bound_pack_must_exist(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach (['show' => 'GET', 'edit' => 'GET', 'update' => 'PATCH', 'destroy' => 'DELETE', 'publish' => 'POST', 'archive' => 'POST', 'unarchive' => 'POST'] as $name => $method) {
            $this->json($method, route('admin.meeting-packs.'.$name, ['plan' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']), $this->payload())->assertNotFound();
        }
    }

    public function test_form_requests_validate_before_reaching_controller(): void
    {
        $plan = MeetingPack::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->postJson(route('admin.meeting-packs.store'), [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'meeting_count', 'price']);
        $this->patchJson(route('admin.meeting-packs.update', $plan), [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'meeting_count', 'price']);
        $this->getJson(route('admin.meeting-packs.index', ['status' => 'unknown']))->assertUnprocessable()->assertJsonValidationErrors('status');
    }
}

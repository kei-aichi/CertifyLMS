<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 10 Route の認証・認可と Binding を検証する。PM回答待ちの操作は501で止まり、DBを変更しないことを保証する。
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function endpoints(Plan $plan): array
    {
        return [
            ['GET', 'index', []], ['GET', 'create', []], ['POST', 'store', []],
            ['GET', 'show', [$plan]], ['GET', 'edit', [$plan]],
            ['PUT', 'update', [$plan]], ['DELETE', 'destroy', [$plan]],
            ['POST', 'publish', [$plan]], ['POST', 'archive', [$plan]],
            ['POST', 'unarchive', [$plan]],
        ];
    }

    private function payload(): array
    {
        return ['name' => 'プラン', 'duration_days' => 30, 'default_meeting_quota' => 0];
    }

    public function test_guests_are_rejected_on_every_endpoint(): void
    {
        $plan = Plan::factory()->create();
        foreach ($this->endpoints($plan) as [$method, $name, $parameters]) {
            $url = route('admin.plans.'.$name, $parameters);
            $this->call($method, $url, $this->payload())->assertRedirect(route('login'));
            $this->json($method, $url, $this->payload())->assertUnauthorized();
        }
    }

    public function test_students_and_coaches_are_forbidden_on_every_endpoint(): void
    {
        $plan = Plan::factory()->create();
        foreach ([User::factory()->student()->create(), User::factory()->coach()->create()] as $user) {
            $this->actingAs($user);
            foreach ($this->endpoints($plan) as [$method, $name, $parameters]) {
                $this->json($method, route('admin.plans.'.$name, $parameters), $this->payload())->assertForbidden();
            }
        }
    }

    public function test_admin_passes_authorization_without_executing_business_operations(): void
    {
        $plan = Plan::factory()->create();
        $before = $plan->fresh()->getRawOriginal();
        $this->actingAs(User::factory()->admin()->create());
        foreach ($this->endpoints($plan) as [$method, $name, $parameters]) {
            $this->json($method, route('admin.plans.'.$name, $parameters), $this->payload())->assertStatus($name === 'create' ? 200 : 501);
        }
        $this->assertDatabaseCount('plans', 1);
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
    }

    public function test_bound_plan_must_exist(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach (['show' => 'GET', 'edit' => 'GET', 'update' => 'PUT', 'destroy' => 'DELETE', 'publish' => 'POST', 'archive' => 'POST', 'unarchive' => 'POST'] as $name => $method) {
            $this->json($method, route('admin.plans.'.$name, ['plan' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']), $this->payload())->assertNotFound();
        }
    }

    public function test_form_requests_validate_before_reaching_controller(): void
    {
        $plan = Plan::factory()->create();
        $this->actingAs(User::factory()->admin()->create());
        $this->postJson(route('admin.plans.store'), [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'duration_days', 'default_meeting_quota']);
        $this->putJson(route('admin.plans.update', $plan), [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'duration_days', 'default_meeting_quota']);
        $this->getJson(route('admin.plans.index', ['status' => 'unknown']))->assertUnprocessable()->assertJsonValidationErrors('status');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanStatus;
use App\Http\Requests\Plan\IndexRequest;
use App\Http\Requests\Plan\StoreRequest;
use App\Http\Requests\Plan\UpdateRequest;
use App\Models\Plan;
use App\UseCases\Plan\IndexAction;
use App\UseCases\Plan\ShowAction;
use App\UseCases\Plan\StoreAction;
use App\UseCases\Plan\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * プラン管理の認可・入力検証の入口。
 * PM回答待ちの業務処理は、認可後に501で停止し、後続StepでActionへ接続する。
 */
class PlanController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $validated = $request->validated();
        $keyword = $validated['keyword'] ?? null;
        $status = $validated['status'] ?? null;

        return view('plan.management.index', [
            'plans' => $action($keyword, $status !== null ? PlanStatus::from($status) : null),
            'keyword' => $keyword,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Plan::class);

        return view('plan.management.create');
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $plan = $action($request->user(), $request->validated());

        return redirect()->route('admin.plans.show', $plan)
            ->with('success', 'プランを作成しました。');
    }

    public function show(Plan $plan, ShowAction $action): View
    {
        $this->authorize('view', $plan);

        return view('plan.management.show', ['plan' => $action($plan)]);
    }

    public function edit(Plan $plan): View
    {
        $this->authorize('update', $plan);

        return view('plan.management.edit', compact('plan'));
    }

    public function update(Plan $plan, UpdateRequest $request, UpdateAction $action): RedirectResponse
    {
        $plan = $action($plan, $request->user(), $request->validated());

        return redirect()->route('admin.plans.show', $plan)
            ->with('success', 'プランを更新しました。');
    }

    public function destroy(Plan $plan): never
    {
        $this->authorize('delete', $plan);

        abort(501);
    }

    public function publish(Plan $plan): never
    {
        $this->authorize('publish', $plan);

        abort(501);
    }

    public function archive(Plan $plan): never
    {
        $this->authorize('archive', $plan);

        abort(501);
    }

    public function unarchive(Plan $plan): never
    {
        $this->authorize('unarchive', $plan);

        abort(501);
    }
}

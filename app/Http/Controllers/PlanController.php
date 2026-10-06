<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanStatus;
use App\Http\Requests\Plan\IndexRequest;
use App\Http\Requests\Plan\StoreRequest;
use App\Http\Requests\Plan\UpdateRequest;
use App\Models\Plan;
use App\UseCases\Plan\IndexAction;
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

    public function store(StoreRequest $request): never
    {
        abort(501);
    }

    public function show(Plan $plan): never
    {
        $this->authorize('view', $plan);

        abort(501);
    }

    public function edit(Plan $plan): never
    {
        $this->authorize('update', $plan);

        abort(501);
    }

    public function update(Plan $plan, UpdateRequest $request): never
    {
        abort(501);
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

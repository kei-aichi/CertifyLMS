<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MeetingPackStatus;
use App\Http\Requests\MeetingPack\IndexRequest;
use App\Http\Requests\MeetingPack\StoreRequest;
use App\Http\Requests\MeetingPack\UpdateRequest;
use App\Models\MeetingPack;
use App\UseCases\MeetingPack\IndexAction;
use App\UseCases\MeetingPack\ShowAction;
use Illuminate\View\View;

/**
 * 面談パック管理の認可・入力検証の入口。
 *
 * 一覧・詳細は Action へ委譲する。残りの操作は後続 Step で接続するため、
 * 成功と誤認させないよう認可・検証後に 501 で終了する。
 */
class MeetingPackController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $validated = $request->validated();
        $keyword = $validated['keyword'] ?? null;
        $status = $validated['status'] ?? null;

        return view('meeting-pack.management.index', [
            'plans' => $action($keyword, $status !== null ? MeetingPackStatus::from($status) : null),
            'keyword' => $keyword,
            'status' => $status,
        ]);
    }

    public function create(): never
    {
        $this->authorize('create', MeetingPack::class);

        // TODO: create の業務処理・応答を後続 Step で接続する。
        abort(501, '面談パック管理の業務処理は未実装です。');
    }

    public function store(StoreRequest $request): never
    {
        // TODO: store の業務処理・応答を後続 Step で接続する。
        abort(501, '面談パック管理の業務処理は未実装です。');
    }

    public function show(MeetingPack $plan, ShowAction $action): View
    {
        $this->authorize('view', $plan);

        return view('meeting-pack.management.show', ['plan' => $action($plan)]);
    }

    public function edit(MeetingPack $plan): never
    {
        $this->authorize('update', $plan);

        // TODO: edit の業務処理・応答を後続 Step で接続する。
        abort(501, '面談パック管理の業務処理は未実装です。');
    }

    public function update(MeetingPack $plan, UpdateRequest $request): never
    {
        // TODO: update の業務処理・応答を後続 Step で接続する。
        abort(501, '面談パック管理の業務処理は未実装です。');
    }

    public function destroy(MeetingPack $plan): never
    {
        $this->authorize('delete', $plan);

        // TODO: destroy の業務処理・応答を後続 Step で接続する。
        abort(501, '面談パック管理の業務処理は未実装です。');
    }

    public function publish(MeetingPack $plan): never
    {
        $this->authorize('publish', $plan);

        // TODO: publish の業務処理・応答を後続 Step で接続する。
        abort(501, '面談パック管理の業務処理は未実装です。');
    }

    public function archive(MeetingPack $plan): never
    {
        $this->authorize('archive', $plan);

        // TODO: archive の業務処理・応答を後続 Step で接続する。
        abort(501, '面談パック管理の業務処理は未実装です。');
    }

    public function unarchive(MeetingPack $plan): never
    {
        $this->authorize('unarchive', $plan);

        // TODO: unarchive の業務処理・応答を後続 Step で接続する。
        abort(501, '面談パック管理の業務処理は未実装です。');
    }
}

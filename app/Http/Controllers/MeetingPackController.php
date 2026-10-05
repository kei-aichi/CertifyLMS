<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MeetingPackStatus;
use App\Http\Requests\MeetingPack\IndexRequest;
use App\Http\Requests\MeetingPack\StoreRequest;
use App\Http\Requests\MeetingPack\UpdateRequest;
use App\Models\MeetingPack;
use App\UseCases\MeetingPack\ArchiveAction;
use App\UseCases\MeetingPack\IndexAction;
use App\UseCases\MeetingPack\PublishAction;
use App\UseCases\MeetingPack\ShowAction;
use App\UseCases\MeetingPack\StoreAction;
use App\UseCases\MeetingPack\UnarchiveAction;
use App\UseCases\MeetingPack\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 面談パック管理の認可・入力検証の入口。
 *
 * 取得・保存処理は Action へ委譲する。削除は後続 Step で接続するため、
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

    public function create(): View
    {
        $this->authorize('create', MeetingPack::class);

        return view('meeting-pack.management.create');
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $plan = $action($request->user(), $request->validated());

        return redirect()->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを作成しました。');
    }

    public function show(MeetingPack $plan, ShowAction $action): View
    {
        $this->authorize('view', $plan);

        return view('meeting-pack.management.show', ['plan' => $action($plan)]);
    }

    public function edit(MeetingPack $plan): View
    {
        $this->authorize('update', $plan);

        return view('meeting-pack.management.edit', ['plan' => $plan]);
    }

    public function update(MeetingPack $plan, UpdateRequest $request, UpdateAction $action): RedirectResponse
    {
        $plan = $action($plan, $request->user(), $request->validated());

        return redirect()->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを更新しました。');
    }

    public function destroy(MeetingPack $plan): never
    {
        $this->authorize('delete', $plan);

        // TODO: destroy の業務処理・応答を後続 Step で接続する。
        abort(501, '面談パック管理の業務処理は未実装です。');
    }

    public function publish(MeetingPack $plan, PublishAction $action): RedirectResponse
    {
        $this->authorize('publish', $plan);

        $action($plan, request()->user());

        return redirect()->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを公開しました。');
    }

    public function archive(MeetingPack $plan, ArchiveAction $action): RedirectResponse
    {
        $this->authorize('archive', $plan);

        $action($plan, request()->user());

        return redirect()->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックをアーカイブしました。');
    }

    public function unarchive(MeetingPack $plan, UnarchiveAction $action): RedirectResponse
    {
        $this->authorize('unarchive', $plan);

        $action($plan, request()->user());

        return redirect()->route('admin.meeting-packs.show', $plan)
            ->with('success', '面談パックを下書きへ戻しました。');
    }
}

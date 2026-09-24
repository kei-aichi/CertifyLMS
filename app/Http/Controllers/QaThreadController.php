<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\QaThread\StoreRequest;
use App\Http\Requests\QaThread\UpdateRequest;
use App\Models\QaThread;
use App\UseCases\QaThread\DestroyAction;
use App\UseCases\QaThread\ResolveAction;
use App\UseCases\QaThread\StoreAction;
use App\UseCases\QaThread\UnresolveAction;
use App\UseCases\QaThread\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 質問の更新操作をActionへ委譲する。
 * GET画面は後続Stepで追加するため、リダイレクトは予定URLを使用する。
 */
class QaThreadController extends Controller
{
    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $thread = $action($request->user(), $request->validated());

        return redirect('qa-board/'.$thread->id)->with('success', '質問を投稿しました。');
    }

    public function update(QaThread $thread, UpdateRequest $request, UpdateAction $action): RedirectResponse
    {
        $action($thread, $request->user(), $request->validated());

        return redirect('qa-board/'.$thread->id)->with('success', '質問を更新しました。');
    }

    public function destroy(QaThread $thread, Request $request, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $thread);

        $action($thread, $request->user());

        $prefix = $request->routeIs('admin.*') ? 'admin/qa-board' : 'qa-board';

        return redirect($prefix)->with('success', '質問を削除しました。');
    }

    public function resolve(QaThread $thread, Request $request, ResolveAction $action): RedirectResponse
    {
        $this->authorize('resolve', $thread);

        $action($thread, $request->user());

        return redirect('qa-board/'.$thread->id)->with('success', '質問を解決済みにしました。');
    }

    public function unresolve(QaThread $thread, Request $request, UnresolveAction $action): RedirectResponse
    {
        $this->authorize('unresolve', $thread);

        $action($thread, $request->user());

        return redirect('qa-board/'.$thread->id)->with('success', '質問を未解決に戻しました。');
    }
}

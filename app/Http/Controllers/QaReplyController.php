<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\QaReply\StoreRequest;
use App\Http\Requests\QaReply\UpdateRequest;
use App\Models\QaReply;
use App\Models\QaThread;
use App\UseCases\QaReply\DestroyAction;
use App\UseCases\QaReply\StoreAction;
use App\UseCases\QaReply\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 回答の更新操作をActionへ委譲する。
 */
class QaReplyController extends Controller
{
    public function edit(QaThread $thread, QaReply $reply): View
    {
        $this->authorize('update', $reply);

        return view('qa-thread.reply-edit', compact('thread', 'reply'));
    }

    public function store(QaThread $thread, StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $action($thread, $request->user(), $request->validated());

        return redirect()->route('qa-board.show', $thread)->with('success', '回答を投稿しました。');
    }

    public function update(QaThread $thread, QaReply $reply, UpdateRequest $request, UpdateAction $action): RedirectResponse
    {
        $action($reply, $request->user(), $request->validated());

        return redirect()->route('qa-board.show', $thread)->with('success', '回答を更新しました。');
    }

    public function destroy(QaThread $thread, QaReply $reply, Request $request, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $reply);

        $action($reply, $request->user());

        $prefix = $request->routeIs('admin.*') ? 'admin.qa-board.show' : 'qa-board.show';

        return redirect()->route($prefix, $thread)->with('success', '回答を削除しました。');
    }
}

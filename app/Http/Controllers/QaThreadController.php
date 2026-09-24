<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\QaThread\IndexRequest;
use App\Http\Requests\QaThread\StoreRequest;
use App\Http\Requests\QaThread\UpdateRequest;
use App\Models\Certification;
use App\Models\QaThread;
use App\UseCases\QaThread\DestroyAction;
use App\UseCases\QaThread\IndexAction;
use App\UseCases\QaThread\ResolveAction;
use App\UseCases\QaThread\ShowAction;
use App\UseCases\QaThread\StoreAction;
use App\UseCases\QaThread\UnresolveAction;
use App\UseCases\QaThread\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 質問の取得・更新操作をActionへ委譲する。
 */
class QaThreadController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        return view('qa-thread.index', $action($request->user(), $request->validated()));
    }

    public function create(): View
    {
        $this->authorize('create', QaThread::class);

        return view('qa-thread.create', ['certifications' => Certification::query()->published()->orderBy('name')->orderBy('id')->get()]);
    }

    public function show(QaThread $thread, Request $request, ShowAction $action): View
    {
        $this->authorize('view', $thread);

        return view('qa-thread.show', ['thread' => $action($thread, $request->user())]);
    }

    public function edit(QaThread $thread): View
    {
        $this->authorize('update', $thread);

        return view('qa-thread.edit', ['thread' => $thread->loadMissing('certification')]);
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $thread = $action($request->user(), $request->validated());

        return redirect()->route('qa-board.show', $thread)->with('success', '質問を投稿しました。');
    }

    public function update(QaThread $thread, UpdateRequest $request, UpdateAction $action): RedirectResponse
    {
        $action($thread, $request->user(), $request->validated());

        return redirect()->route('qa-board.show', $thread)->with('success', '質問を更新しました。');
    }

    public function destroy(QaThread $thread, Request $request, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $thread);

        $action($thread, $request->user());

        $prefix = $request->routeIs('admin.*') ? 'admin.qa-board.index' : 'qa-board.index';

        return redirect()->route($prefix)->with('success', '質問を削除しました。');
    }

    public function resolve(QaThread $thread, Request $request, ResolveAction $action): RedirectResponse
    {
        $this->authorize('resolve', $thread);

        $action($thread, $request->user());

        return redirect()->route('qa-board.show', $thread)->with('success', '質問を解決済みにしました。');
    }

    public function unresolve(QaThread $thread, Request $request, UnresolveAction $action): RedirectResponse
    {
        $this->authorize('unresolve', $thread);

        $action($thread, $request->user());

        return redirect()->route('qa-board.show', $thread)->with('success', '質問を未解決に戻しました。');
    }
}

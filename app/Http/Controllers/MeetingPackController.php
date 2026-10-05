<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MeetingPack\IndexRequest;
use App\Http\Requests\MeetingPack\StoreRequest;
use App\Http\Requests\MeetingPack\UpdateRequest;
use App\Models\MeetingPack;

/**
 * 面談パック管理の認可・入力検証の入口。
 *
 * Action は後続 Step で接続する。未接続の操作を成功と誤認させないため、
 * 現段階では認可・検証後に 501 で終了し、取得・保存・状態変更は行わない。
 */
class MeetingPackController extends Controller
{
    public function index(IndexRequest $request): never
    {
        // TODO: index の業務処理・応答を後続 Step で接続する。
        abort(501, '面談パック管理の業務処理は未実装です。');
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

    public function show(MeetingPack $plan): never
    {
        $this->authorize('view', $plan);

        // TODO: show の業務処理・応答を後続 Step で接続する。
        abort(501, '面談パック管理の業務処理は未実装です。');
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

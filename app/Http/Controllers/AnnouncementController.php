<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\Announcement\StoreRequest;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\UseCases\Announcement\DispatchAnnouncementAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class AnnouncementController extends Controller
{
    public function index(): View
    {
        $announcements = Announcement::query()
            ->with(['createdBy', 'targetUser', 'targetCertification'])
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('announcement.management.index', compact('announcements'));
    }

    public function create(): View
    {
        return view('announcement.management.create', [
            'certifications' => Certification::query()->orderBy('name')->get(),
            'students' => User::query()
                ->where('role', UserRole::Student->value)
                ->where('status', UserStatus::InProgress->value)
                ->orderBy('name')
                ->get(),
            'submissionKey' => (string) Str::uuid(),
        ]);
    }

    public function show(Announcement $announcement): View
    {
        $announcement->load([
            'createdBy',
            'targetUser',
            'targetCertification',
        ]);

        return view('announcement.management.show', compact('announcement'));
    }

    public function store(StoreRequest $request, DispatchAnnouncementAction $action): RedirectResponse
    {
        $result = $action($request->user(), $request->validated());
        $announcement = $result->announcement;

        if ($result->alreadyExists) {
            return redirect()->route('admin.announcements.show', $announcement)
                ->with('status', '同じ送信キーのお知らせは既に処理されています。既存の配信履歴を表示します。');
        }

        if ($announcement->dispatch_status?->value === 'failed') {
            return redirect()->route('admin.announcements.show', $announcement)
                ->with('error', '配信処理でエラーが発生しました。一部の受講生に配信済みの可能性があります。配信履歴を確認し、個別に対応してください。');
        }

        return redirect()->route('admin.announcements.show', $announcement)
            ->with('status', 'お知らせを配信しました。');
    }
}

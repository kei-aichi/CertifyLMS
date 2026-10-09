<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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

    public function storeNotImplemented(): never
    {
        abort(Response::HTTP_NOT_IMPLEMENTED, 'Announcement dispatch is not implemented yet.');
    }
}

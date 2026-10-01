<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;

/** 一覧と資格選択肢を同じ公開・担当範囲で取得する。Enrollmentは参照しない。 */
final class IndexAction
{
    /** @param array{keyword?: ?string, certification_id?: ?string, status?: ?string, page?: mixed} $filters */
    public function __invoke(User $viewer, array $filters): array
    {
        $certifications = Certification::query();
        if ($viewer->role !== UserRole::Admin) {
            $certifications->published();
        }
        if ($viewer->role === UserRole::Coach) {
            $certifications->assignedTo($viewer);
        }

        $query = QaThread::query()
            ->whereIn('certification_id', (clone $certifications)->select('id'))
            ->with(['certification', 'user' => fn ($query) => $query->withTrashed()])
            ->withCount('replies');

        if ($filters['certification_id'] ?? null) {
            $query->where('certification_id', $filters['certification_id']);
        }
        if ($filters['status'] ?? null) {
            $query->where('status', $filters['status'] === 'unresolved' ? QaThreadStatus::Open->value : $filters['status']);
        }
        $keyword = $filters['keyword'] ?? '';
        if ($keyword !== '') {
            $pattern = '%'.$keyword.'%';
            // OR条件を括り、回答の一致によって公開・担当範囲が広がらないようにする。
            $query->where(fn ($query) => $query->where('title', 'like', $pattern)
                ->orWhere('body', 'like', $pattern)
                ->orWhereHas('replies', fn ($reply) => $reply->where('body', 'like', $pattern)));
        }

        return [
            'threads' => $query->latest()->orderByDesc('id')->paginate(20)->withQueryString(),
            'certifications' => $certifications->orderBy('name')->orderBy('id')->get(),
            'filters' => array_replace($filters, ['status' => ($filters['status'] ?? '') === 'open' ? 'unresolved' : ($filters['status'] ?? '')]),
            'publishedStatus' => CertificationStatus::Published,
        ];
    }
}

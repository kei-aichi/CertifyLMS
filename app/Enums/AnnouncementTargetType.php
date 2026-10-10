<?php

declare(strict_types=1);

namespace App\Enums;

enum AnnouncementTargetType: string
{
    case AllStudents = 'all';
    case Certification = 'certification';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::AllStudents => '全受講生',
            self::Certification => '資格指定',
            self::User => '受講生指定',
        };
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\AnnouncementDispatchStatus;
use App\Enums\AnnouncementTargetType;
use Tests\TestCase;

final class AnnouncementTest extends TestCase
{
    public function test_target_type_cases_values_and_labels_match_the_specification(): void
    {
        $this->assertSame(
            [
                AnnouncementTargetType::AllStudents,
                AnnouncementTargetType::Certification,
                AnnouncementTargetType::User,
            ],
            AnnouncementTargetType::cases(),
        );
        $this->assertSame('all', AnnouncementTargetType::AllStudents->value);
        $this->assertSame('certification', AnnouncementTargetType::Certification->value);
        $this->assertSame('user', AnnouncementTargetType::User->value);
        $this->assertSame('全受講生', AnnouncementTargetType::AllStudents->label());
        $this->assertSame('資格指定', AnnouncementTargetType::Certification->label());
        $this->assertSame('受講生指定', AnnouncementTargetType::User->label());
    }

    public function test_dispatch_status_cases_values_and_labels_match_the_specification(): void
    {
        $this->assertSame(
            [
                AnnouncementDispatchStatus::Processing,
                AnnouncementDispatchStatus::Succeeded,
                AnnouncementDispatchStatus::Failed,
            ],
            AnnouncementDispatchStatus::cases(),
        );
        $this->assertSame('processing', AnnouncementDispatchStatus::Processing->value);
        $this->assertSame('succeeded', AnnouncementDispatchStatus::Succeeded->value);
        $this->assertSame('failed', AnnouncementDispatchStatus::Failed->value);
        $this->assertSame('処理中または完了未確認', AnnouncementDispatchStatus::Processing->label());
        $this->assertSame('配信完了', AnnouncementDispatchStatus::Succeeded->label());
        $this->assertSame('配信失敗', AnnouncementDispatchStatus::Failed->label());
    }
}

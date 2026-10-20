<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\PaymentStatus;
use PHPUnit\Framework\TestCase;

class PaymentStatusTest extends TestCase
{
    public function test_values_and_labels_are_defined(): void
    {
        $this->assertSame(['pending', 'succeeded', 'failed', 'refunded'], array_map(
            static fn (PaymentStatus $status): string => $status->value,
            PaymentStatus::cases(),
        ));
        $this->assertSame('保留', PaymentStatus::Pending->label());
        $this->assertSame('支払済み', PaymentStatus::Succeeded->label());
        $this->assertSame('失敗', PaymentStatus::Failed->label());
        $this->assertSame('返金済み', PaymentStatus::Refunded->label());
    }
}

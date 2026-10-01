<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QaThread>
 */
class QaThreadFactory extends Factory
{
    protected $model = QaThread::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // 未受講の公開資格にも質問できるため、Enrollment は生成しない。
        return [
            'certification_id' => Certification::factory()->published(),
            'user_id' => User::factory()->student()->inProgress(),
            'title' => fake()->sentence(),
            'body' => fake()->paragraph(),
            'status' => QaThreadStatus::Open->value,
            'resolved_at' => null,
        ];
    }

    /**
     * 解決済みの state に重ねても、未解決に解決日時が残らないようにする。
     */
    public function open(): static
    {
        return $this->state(fn () => [
            'status' => QaThreadStatus::Open->value,
            'resolved_at' => null,
        ]);
    }

    /**
     * 自己解決も許可されるため、回答は自動生成せず状態と日時だけを設定する。
     */
    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => QaThreadStatus::Resolved->value,
            'resolved_at' => now(),
        ]);
    }
}

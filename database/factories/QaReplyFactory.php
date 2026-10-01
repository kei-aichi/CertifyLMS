<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QaReply>
 */
class QaReplyFactory extends Factory
{
    protected $model = QaReply::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // コーチの担当割当等はテスト側で明示し、Factory が認可条件を勝手に補完しない。
        return [
            'qa_thread_id' => QaThread::factory(),
            'user_id' => User::factory()->student()->inProgress(),
            'body' => fake()->paragraph(),
        ];
    }
}

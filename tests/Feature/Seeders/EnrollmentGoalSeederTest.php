<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Database\Seeders\CertificationCategorySeeder;
use Database\Seeders\CertificationSeeder;
use Database\Seeders\EnrollmentSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentGoalSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_seeder_creates_fixed_student_goals_for_display(): void
    {
        $this->seed([UserSeeder::class, CertificationCategorySeeder::class, CertificationSeeder::class]);
        $this->seed(EnrollmentSeeder::class);

        $student = User::query()->where('email', 'student@certify-lms.test')->firstOrFail();
        $enrollment = Enrollment::query()->where('user_id', $student->id)->oldest()->firstOrFail();
        $goals = $enrollment->goals()->get();

        $this->assertCount(4, $goals);
        $this->assertSame(3, $goals->whereNull('achieved_at')->count());
        $this->assertSame(1, $goals->whereNotNull('achieved_at')->count());
        $this->assertSame(2, $goals->whereNotNull('target_date')->count());
        $this->assertSame(2, $goals->whereNull('target_date')->count());
        $this->assertTrue($goals->every(fn (EnrollmentGoal $goal): bool => $goal->enrollment_id === $enrollment->id));

        // EnrollmentSeeder全体の再実行は、既存のdemo Enrollment生成が
        // unique制約により失敗するため、このテストでは初回生成結果のみを確認する。
        // 個人目標の投入処理は同一Enrollment・titleをキーにfirstOrCreateしている。
    }
}

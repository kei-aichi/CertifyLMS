<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\AiChatSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiChatSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_idempotent_fixed_student_chat_data(): void
    {
        $student = User::factory()->student()->inProgress()->create(['email' => 'student@certify-lms.test']);
        $certification = Certification::factory()->published()->create();
        $part = Part::factory()->forCertification($certification)->published()->create();
        $chapter = Chapter::factory()->forPart($part)->published()->create();
        Section::factory()->forChapter($chapter)->published()->create();
        Enrollment::factory()->for($student)->for($certification)->learning()->create();

        $this->seed(AiChatSeeder::class);
        $this->seed(AiChatSeeder::class);

        $this->assertDatabaseCount('ai_chat_conversations', 3);
        $this->assertDatabaseCount('ai_chat_messages', 6);
        $this->assertDatabaseHas('ai_chat_conversations', ['user_id' => $student->id, 'section_id' => null]);
        $this->assertDatabaseHas('ai_chat_messages', ['role' => 'assistant', 'status' => 'error', 'error_detail' => 'gemini_api_error']);
        $this->assertDatabaseCount('ai_chat_daily_usages', 0);
    }

    public function test_it_exits_when_fixed_student_is_missing(): void
    {
        $this->seed(AiChatSeeder::class);

        $this->assertDatabaseCount('ai_chat_conversations', 0);
        $this->assertDatabaseCount('ai_chat_messages', 0);
    }
}

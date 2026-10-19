<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GoogleCredential;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 開発・テスト環境用のGoogle Calendar連携状態。
 *
 * 実Google APIへ接続できないダミーCredentialのみを投入し、
 * 本番環境では何も作成しない。
 */
final class GoogleCalendarSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $coach = User::query()->where('email', 'coach@certify-lms.test')->first();
        if ($coach === null) {
            return;
        }

        GoogleCredential::query()->firstOrCreate(
            ['coach_id' => $coach->getKey()],
            [
                'calendar_id' => 'primary',
                'access_token' => 'demo-access-token-never-send-to-google',
                'refresh_token' => 'demo-refresh-token-never-send-to-google',
                'expires_at' => now()->addHour(),
                'connected_at' => now(),
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\GoogleCredential;
use App\Services\GoogleOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class GoogleCalendarController extends Controller
{
    public function __construct(private readonly GoogleOAuthService $oauth) {}

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(64);
        $redirectPath = $request->string('redirect_path')->toString();
        $redirectPath = $redirectPath === '/settings/availability' ? $redirectPath : '/settings/availability';

        $request->session()->put('google_calendar.oauth', [
            'state' => $state,
            'coach_id' => (string) $request->user()->getKey(),
            'redirect_path' => $redirectPath,
        ]);

        try {
            return redirect()->away($this->oauth->authorizationUrl($state));
        } catch (Throwable) {
            $request->session()->forget('google_calendar.oauth');

            return redirect($redirectPath)->with('error', 'Googleカレンダー連携を開始できませんでした。');
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        $oauth = $request->session()->pull('google_calendar.oauth');
        $redirectPath = is_array($oauth) && ($oauth['redirect_path'] ?? null) === '/settings/availability'
            ? $oauth['redirect_path']
            : '/settings/availability';

        if (! is_array($oauth)
            || ! is_string($oauth['state'] ?? null)
            || ! is_string($request->query('state'))
            || ! hash_equals($oauth['state'], $request->query('state'))
            || (string) ($oauth['coach_id'] ?? '') !== (string) $request->user()->getKey()) {
            return redirect($redirectPath)->with('error', 'Google認証の検証に失敗しました。');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect($redirectPath)->with('error', 'Google認証がキャンセルまたは失敗しました。');
        }

        try {
            $token = $this->oauth->exchangeCode((string) $request->query('code'));
            if (empty($token['access_token']) || empty($token['refresh_token'])) {
                throw new \RuntimeException('Incomplete Google OAuth credentials.');
            }

            DB::transaction(function () use ($request, $token): void {
                $user = $request->user();
                $user->googleCredential()?->delete();
                GoogleCredential::create([
                    'coach_id' => $user->getKey(),
                    'calendar_id' => 'primary',
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'],
                    'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
                    'connected_at' => now(),
                ]);
            });
        } catch (Throwable) {
            return redirect($redirectPath)->with('error', 'Googleカレンダー連携に失敗しました。');
        }

        return redirect($redirectPath)->with('success', 'Googleカレンダーを連携しました。');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->googleCredential()?->delete();

        return redirect('/settings/availability')->with('success', 'Googleカレンダーの連携を解除しました。');
    }
}

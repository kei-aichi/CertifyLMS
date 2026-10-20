<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MeetingQuota\CreateCheckoutRequest;
use App\UseCases\MeetingQuota\CreateCheckoutSessionAction;
use App\UseCases\MeetingQuota\ShowCheckoutAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class MeetingQuotaCheckoutController extends Controller
{
    public function select(ShowCheckoutAction $action): View
    {
        return view('meeting-quota.checkout-select', ['plans' => $action()]);
    }

    public function create(CreateCheckoutRequest $request, CreateCheckoutSessionAction $action): RedirectResponse
    {
        try {
            $result = $action($request->user(), $request->validated('meeting_pack_id'));
        } catch (\Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface || $exception instanceof ModelNotFoundException) {
                throw $exception;
            }

            Log::warning('Stripe Checkout Sessionの作成に失敗しました。', [
                'user_id' => $request->user()?->getKey(),
                'exception' => $exception::class,
            ]);

            return redirect()->route('meeting-quota.checkout.select')
                ->with('error', '決済画面を開始できませんでした。時間をおいて再度お試しください。');
        }

        return redirect()->away($result['session']->url);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Models\Payment;
use App\UseCases\MeetingQuota\FulfillCheckoutAction;
use App\Exceptions\MeetingQuota\CheckoutFulfillmentException;

final class MeetingQuotaCheckoutSuccessController extends Controller
{
    public function __invoke(Request $request, FulfillCheckoutAction $fulfill): View
    {
        abort_unless(
            is_string($request->query('payment')) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $request->query('payment')) === 1
                && is_string($request->query('session_id'))
                && $request->query('session_id') !== '',
            422,
        );

        $payment = Payment::query()->whereKey($request->query('payment'))
            ->where('user_id', $request->user()->getKey())->firstOrFail();

        try {
            $payment = $fulfill((string) $request->query('session_id'), (string) $payment->getKey());
        } catch (CheckoutFulfillmentException $exception) {
            return view('meeting-quota.success-pending', [
                'message' => $exception->category === 'permanent_mismatch'
                    ? '決済情報を確認できませんでした。管理者へお問い合わせください。'
                    : '決済結果を確認しています。時間をおいて再度ご確認ください。',
            ]);
        }

        return view('meeting-quota.success', ['payment' => $payment]);
    }
}

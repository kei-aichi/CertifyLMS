<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

final class MeetingQuotaCheckoutSuccessController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless(
            is_string($request->query('payment')) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $request->query('payment')) === 1
                && is_string($request->query('session_id'))
                && $request->query('session_id') !== '',
            422,
        );

        return view('meeting-quota.success-pending');
    }
}

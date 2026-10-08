<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

final class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('settings.profile', [
            'user' => request()->user(),
        ]);
    }

    public function update(): never
    {
        abort(501);
    }

    public function storeAvatar(): never
    {
        abort(501);
    }

    public function destroyAvatar(): never
    {
        abort(501);
    }

    public function updatePassword(): never
    {
        abort(501);
    }
}

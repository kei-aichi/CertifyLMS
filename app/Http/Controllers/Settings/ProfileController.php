<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\UseCases\Settings\UpdateProfileAction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('settings.profile', [
            'user' => request()->user(),
        ]);
    }

    public function update(ProfileUpdateRequest $request, UpdateProfileAction $action): RedirectResponse
    {
        $action($request->user(), $request->validated());

        return redirect()->route('settings.profile.edit')->with('success', 'プロフィールを更新しました。');
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

<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Sets or changes the user's Stock Opname PIN — a short numeric
     * credential separate from their account password, only meaningful for
     * roles that can participate in Stock Opname (see
     * App\Support\StockOpnamePin for why this exists).
     */
    public function updateOpnamePin(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->mayParticipateInStockOpname(), 403);

        $validated = $request->validateWithBag('updateOpnamePin', [
            'stock_opname_pin' => ['required', 'digits:6', 'confirmed'],
        ]);

        // The PIN alone identifies the user on the public item page, so two staff
        // cannot share one (the audit trail would credit the wrong person).
        if (\App\Support\StockOpnamePin::isTakenByAnother($validated['stock_opname_pin'], $user->id)) {
            return Redirect::route('profile.edit')->withErrors(['stock_opname_pin' => 'That PIN is already used by someone else. Choose a different PIN.'], 'updateOpnamePin');
        }

        $user->stock_opname_pin = $validated['stock_opname_pin'];
        $user->save();

        return Redirect::route('profile.edit')->with('status', 'opname-pin-updated');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ShiftSession;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        $users = User::query()->orderBy('name')->get();

        return view('users.index', compact('users'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureRoleMutationAllowed($request->input('role'));

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:super_admin,management,admin,supervisor,accounting',
        ]);

        $validated['password'] = bcrypt($validated['password']);
        // Set only by the "Store & User management" guided tour's Add User
        // step (a hidden field it flips on for the duration the tour is
        // open) — tags the row so it's clearly marked in the list and gets
        // swept up by users.tutorial.exit when the tour closes. Never set
        // by the real form, so real user creation is unaffected.
        $validated['is_tutorial'] = $request->boolean('is_tutorial');

        // A practice account starts with a dummy PIN already set, so the
        // tour's own "Reset PIN" step (resources/views/partials/
        // store-user-tour.blade.php) has a real "Reset PIN" button to click
        // on this same row right away — never set for a real user.
        if ($validated['is_tutorial']) {
            $validated['stock_opname_pin'] = '000000';
        }

        User::create($validated);

        return redirect()->route('users.index')->with('success', 'User created successfully.');
    }

    /**
     * Sweep up any leftover practice accounts created by the "Store & User
     * management" guided tour's Add User step — fired when that tour closes,
     * however it closes (finished, skipped, or dismissed). Blanket delete
     * rather than scoped to the current actor: only Management/Super Admin
     * can create these in the first place, so a leftover from an abandoned
     * tour (browser closed mid-way) is still safe for any of them to clear.
     */
    public function tutorialExit(): \Illuminate\Http\JsonResponse
    {
        User::where('is_tutorial', true)->delete();

        return response()->json(['ok' => true]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->ensureTargetUserEditable($user);
        $this->ensureRoleMutationAllowed($request->input('role'));

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,'.$user->id,
            'role' => 'required|in:super_admin,management,admin,supervisor,accounting',
            'password' => 'nullable|string|min:8',
        ]);

        if (empty($validated['password'])) {
            unset($validated['password']);
        } else {
            $validated['password'] = bcrypt($validated['password']);
        }

        $user->update($validated);

        return redirect()->route('users.index')->with('success', 'User updated successfully.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->ensureTargetUserEditable($user);

        if ($user->id === auth()->id()) {
            return redirect()->route('users.index')->with('error', 'You cannot delete your own account.');
        }

        // Shift rows cascade on the opener's user id (C4-03): deleting the account would erase the shift
        // and its closing totals. Until a safe removal exists, an account with shift history stays.
        // Unscoped on purpose: the shift may belong to another store than the one being viewed.
        if (ShiftSession::withoutGlobalScopes()->where('opened_by_user_id', $user->id)->exists()) {
            return redirect()->route('users.index')->with('error', $user->name.' has shift history, and deleting the account would also remove those shifts and their totals. The account was kept.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted successfully.');
    }

    public function toggleRole(User $user): RedirectResponse
    {
        $user->update([
            'role' => $user->isSuperAdmin() ? 'admin' : 'super_admin',
        ]);

        return redirect()->route('users.index')->with('success', 'User role updated.');
    }

    /**
     * Clears a staff member's Stock Opname PIN so they can set a fresh one
     * themselves from Profile — Management/Super Admin never see or choose
     * the actual PIN value, same trust boundary as an account password
     * reset. Answers "where can a super admin / management
     * user reset it?" for a staff member who forgot their PIN.
     */
    public function resetOpnamePin(User $user): RedirectResponse
    {
        $this->ensureTargetUserEditable($user);

        if (! $user->hasStockOpnamePin()) {
            return redirect()->route('users.index')->with('error', $user->name.' has never set a stock opname PIN.');
        }

        $user->stock_opname_pin = null;
        $user->save();

        return redirect()->route('users.index')->with('success', 'Stock opname PIN for '.$user->name.' has been reset — they need to set a new PIN from the Profile page.');
    }

    private function ensureRoleMutationAllowed(?string $requestedRole): void
    {
        $actor = auth()->user();
        if (! $actor) {
            abort(403);
        }

        if ($requestedRole === 'super_admin' && ! $actor->isSuperAdmin()) {
            abort(403, 'Only super admin can assign the super admin role.');
        }
    }

    private function ensureTargetUserEditable(User $target): void
    {
        $actor = auth()->user();
        if (! $actor) {
            abort(403);
        }

        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            abort(403, 'Only super admin can manage super admin accounts.');
        }
    }
}

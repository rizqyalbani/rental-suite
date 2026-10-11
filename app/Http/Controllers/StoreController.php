<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class StoreController extends Controller
{
    public function index(): View
    {
        $stores = Store::query()
            ->with(['users' => fn ($q) => $q->orderBy('name')])
            ->orderBy('id')
            ->get();
        $storeLogoUrls = [];
        $storeLogoDataUris = [];
        $storeAdminPhones = [];
        $storePrimaryColors = [];
        $storeContactAddresses = [];
        $storeGeofences = [];
        $storeLogoRows = StoreSetting::query()
            ->whereIn('store_id', $stores->pluck('id')->all())
            ->whereIn('key', [
                'store_logo_path', 'store_admin_phone', 'store_admin_phone_1', 'store_admin_phone_2',
                'store_contact_phone', 'store_contact_address', 'store_primary_color',
                'geofence_enabled', 'geofence_latitude', 'geofence_longitude', 'geofence_radius_m',
            ])
            ->get();
        foreach ($storeLogoRows as $row) {
            $storeId = (int) $row->store_id;
            $raw = $row->value !== null ? (string) $row->value : '';
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            // geofence_* values are numbers/booleans, not strings — the
            // generic $value below assumes a string-ish setting, so these
            // are pulled straight off the decoded value instead.
            if (str_starts_with((string) $row->key, 'geofence_')) {
                $storeGeofences[$storeId][$row->key] = $decoded;
                continue;
            }
            $value = is_string($decoded) ? trim($decoded) : trim($raw, "\" \t\n\r\0\x0B");
            if ($row->key === 'store_logo_path') {
                if ($value !== '' && Storage::disk('public')->exists($value)) {
                    $storeLogoUrls[$storeId] = Storage::disk('public')->url($value);
                    try {
                        $binary = Storage::disk('public')->get($value);
                        if (is_string($binary) && $binary !== '') {
                            $ext = strtolower((string) pathinfo($value, PATHINFO_EXTENSION));
                            $mime = match ($ext) {
                                'png' => 'image/png',
                                'jpg', 'jpeg' => 'image/jpeg',
                                'webp' => 'image/webp',
                                default => 'application/octet-stream',
                            };
                            $storeLogoDataUris[$storeId] = 'data:'.$mime.';base64,'.base64_encode($binary);
                        }
                    } catch (\Throwable) {
                    }
                }
                continue;
            }
            if ($row->key === 'store_contact_address') {
                if ($value !== '') {
                    $storeContactAddresses[$storeId] = $value;
                }
                continue;
            }
            if ($row->key === 'store_primary_color') {
                if ($value !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                    $storePrimaryColors[$storeId] = strtolower($value);
                }
                continue;
            }
            if ($value !== '') {
                if ($row->key === 'store_admin_phone_1') {
                    $storeAdminPhones[$storeId]['phone_1'] = $value;
                } elseif ($row->key === 'store_admin_phone_2') {
                    $storeAdminPhones[$storeId]['phone_2'] = $value;
                } elseif (! isset($storeAdminPhones[$storeId]['phone_1']) && $row->key === 'store_admin_phone') {
                    $storeAdminPhones[$storeId]['phone_1'] = $value;
                } elseif (! isset($storeAdminPhones[$storeId]['phone_1']) && $row->key === 'store_contact_phone') {
                    $storeAdminPhones[$storeId]['phone_1'] = $value;
                }
            }
        }
        $admins = User::query()->orderBy('name')->get();

        return view('stores.index', compact('stores', 'admins', 'storeLogoUrls', 'storeLogoDataUris', 'storeAdminPhones', 'storePrimaryColors', 'storeContactAddresses', 'storeGeofences'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash', 'unique:stores,code'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        Store::query()->create([
            'name' => trim((string) $validated['name']),
            'code' => strtolower(trim((string) $validated['code'])),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return redirect()->route('stores.index')->with('success', 'Store created.');
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash', 'unique:stores,code,'.$store->id],
            'is_active' => ['nullable', 'boolean'],
            'store_logo' => ['nullable'],
            'store_admin_phone_1' => ['nullable', 'string', 'max:120'],
            'store_admin_phone_2' => ['nullable', 'string', 'max:120'],
            'store_contact_address' => ['nullable', 'string', 'max:500'],
            'store_primary_color' => ['nullable', 'string', 'max:20'],
            'geofence_enabled' => ['nullable', 'boolean'],
            'geofence_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'geofence_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'geofence_radius_m' => ['nullable', 'integer', 'min:20', 'max:5000'],
        ]);

        $store->update([
            'name' => trim((string) $validated['name']),
            'code' => strtolower(trim((string) $validated['code'])),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        $file = $request->file('store_logo');
        if ($file !== null) {
            if (! $file->isValid()) {
                return redirect()->route('stores.index')->withErrors(['store_logo' => 'Logo upload failed: '.$file->getErrorMessage()]);
            }
            $size = (int) ($file->getSize() ?? 0);
            if ($size > 2 * 1024 * 1024) {
                return redirect()->route('stores.index')->withErrors(['store_logo' => 'Logo is too large. Max size is 2MB.']);
            }
            $ext = strtolower((string) $file->getClientOriginalExtension());
            if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                return redirect()->route('stores.index')->withErrors(['store_logo' => 'Logo must be a PNG, JPG, JPEG, or WEBP file.']);
            }
            $realPath = (string) ($file->getRealPath() ?? '');
            if ($realPath === '' || ! is_file($realPath) || @getimagesize($realPath) === false) {
                return redirect()->route('stores.index')->withErrors(['store_logo' => 'Logo must be a valid image file.']);
            }
            $newPath = "store-logos/{$store->id}/logo.{$ext}";
            $existingRow = StoreSetting::query()->where('store_id', $store->id)->where('key', 'store_logo_path')->first();
            $existingRaw = $existingRow?->value !== null ? (string) $existingRow->value : '';
            $existingDecoded = $existingRaw !== '' ? json_decode($existingRaw, true) : null;
            $existingPath = is_string($existingDecoded) ? trim($existingDecoded) : trim($existingRaw, "\" \t\n\r\0\x0B");
            if ($existingPath !== '' && $existingPath !== $newPath && Storage::disk('public')->exists($existingPath)) {
                Storage::disk('public')->delete($existingPath);
            }
            try {
                Storage::disk('public')->makeDirectory(dirname($newPath));
                $stored = Storage::disk('public')->putFileAs(dirname($newPath), $file, basename($newPath));
                if (! $stored) {
                    return redirect()->route('stores.index')->withErrors(['store_logo' => 'Failed to upload logo. Check server write permissions for storage/app/public.']);
                }
                StoreSetting::query()->updateOrCreate(
                    ['store_id' => $store->id, 'key' => 'store_logo_path'],
                    ['value' => json_encode($newPath)],
                );
            } catch (\Throwable $e) {
                Log::warning('Store logo upload failed.', [
                    'store_id' => $store->id,
                    'path' => $newPath,
                    'message' => $e->getMessage(),
                ]);
                return redirect()->route('stores.index')->withErrors(['store_logo' => 'Failed to upload logo: '.$e->getMessage()]);
            }
        }

        $adminPhone1 = trim((string) ($validated['store_admin_phone_1'] ?? ''));
        $adminPhone2 = trim((string) ($validated['store_admin_phone_2'] ?? ''));
        if ($adminPhone1 !== '') {
            StoreSetting::query()->updateOrCreate(
                ['store_id' => $store->id, 'key' => 'store_admin_phone_1'],
                ['value' => json_encode($adminPhone1)],
            );
        } else {
            StoreSetting::query()->where('store_id', $store->id)->where('key', 'store_admin_phone_1')->delete();
        }
        if ($adminPhone2 !== '') {
            StoreSetting::query()->updateOrCreate(
                ['store_id' => $store->id, 'key' => 'store_admin_phone_2'],
                ['value' => json_encode($adminPhone2)],
            );
        } else {
            StoreSetting::query()->where('store_id', $store->id)->where('key', 'store_admin_phone_2')->delete();
        }
        StoreSetting::query()->where('store_id', $store->id)->where('key', 'store_admin_phone')->delete();

        $address = trim((string) ($validated['store_contact_address'] ?? ''));
        if ($address !== '') {
            StoreSetting::query()->updateOrCreate(
                ['store_id' => $store->id, 'key' => 'store_contact_address'],
                ['value' => json_encode($address)],
            );
        } else {
            StoreSetting::query()->where('store_id', $store->id)->where('key', 'store_contact_address')->delete();
        }

        $primaryColor = trim((string) ($validated['store_primary_color'] ?? ''));
        if ($primaryColor !== '') {
            if ($primaryColor[0] !== '#') {
                $primaryColor = '#'.$primaryColor;
            }
            if (! preg_match('/^#[0-9a-fA-F]{6}$/', $primaryColor)) {
                return redirect()->route('stores.index')->withErrors(['store_primary_color' => 'Theme color must be a hex color like #166534.']);
            }
            StoreSetting::query()->updateOrCreate(
                ['store_id' => $store->id, 'key' => 'store_primary_color'],
                ['value' => json_encode(strtolower($primaryColor))],
            );
        } else {
            StoreSetting::query()->where('store_id', $store->id)->where('key', 'store_primary_color')->delete();
        }

        // Geofence — opt-in per store (App\Support\Geofence): refuse to
        // enable it without a location and radius already set, since an
        // enabled-but-unconfigured geofence would silently block every
        // Admin/Supervisor login for this store rather than fail loud here.
        $geofenceEnabled = (bool) ($validated['geofence_enabled'] ?? false);
        $hasCoords = array_key_exists('geofence_latitude', $validated) && $validated['geofence_latitude'] !== null
            && array_key_exists('geofence_longitude', $validated) && $validated['geofence_longitude'] !== null
            && array_key_exists('geofence_radius_m', $validated) && $validated['geofence_radius_m'] !== null;
        if ($geofenceEnabled && ! $hasCoords) {
            return redirect()->route('stores.index')->withErrors([
                'geofence_enabled' => 'Set the location (use the "Use My Current Location" button) and the radius before enabling the geofence.',
            ]);
        }
        StoreSetting::query()->updateOrCreate(
            ['store_id' => $store->id, 'key' => 'geofence_enabled'],
            ['value' => json_encode($geofenceEnabled)],
        );
        if ($hasCoords) {
            StoreSetting::query()->updateOrCreate(
                ['store_id' => $store->id, 'key' => 'geofence_latitude'],
                ['value' => json_encode((float) $validated['geofence_latitude'])],
            );
            StoreSetting::query()->updateOrCreate(
                ['store_id' => $store->id, 'key' => 'geofence_longitude'],
                ['value' => json_encode((float) $validated['geofence_longitude'])],
            );
            StoreSetting::query()->updateOrCreate(
                ['store_id' => $store->id, 'key' => 'geofence_radius_m'],
                ['value' => json_encode((int) $validated['geofence_radius_m'])],
            );
        }

        return redirect()->route('stores.index')->with('success', 'Store updated.');
    }

    public function assignUsers(Request $request, Store $store): RedirectResponse
    {
        $validated = $request->validate([
            'user_ids' => ['array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);
        $userIds = collect($validated['user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $store->users()->sync($userIds);

        return redirect()->route('stores.index')->with('success', 'Store users updated.');
    }

    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'min:1'],
            'redirect_to' => ['nullable', 'string', 'max:2000'],
        ]);

        $request->session()->put('current_store_id', (int) $validated['store_id']);
        $redirectTo = (string) ($validated['redirect_to'] ?? url()->previous());
        if ($redirectTo === '' || str_starts_with($redirectTo, 'http://') || str_starts_with($redirectTo, 'https://')) {
            return redirect()->back();
        }

        return redirect($redirectTo);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Rental;
use App\Models\Store;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\Outfit;
use App\Support\AdminDeleteRestriction;
use App\Support\Geofence;
use App\Support\StockOpnamePin;
use App\Support\StoreCache;
use App\Support\StoreContext;
use App\Support\OutfitDuplicateCodeGroups;
use App\Support\OutfitPrefixFormatOutliers;
use App\Support\OutfitSkippedNumberSequence;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OutfitController extends Controller
{
    private function rejectUnlessItemMutatorRedirect(Request $request): ?RedirectResponse
    {
        $actor = $request->user();
        if ($actor && $actor->mayMutateItems()) {
            return null;
        }

        return redirect()->route('outfits.index')->with('error', 'Your account cannot create, update, or delete items.');
    }

    private function rejectUnlessItemMutator(Request $request): RedirectResponse|JsonResponse|null
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        $wantsJson = $request->expectsJson()
            || str_contains($accept, 'application/json')
            || str_contains($accept, '+json');

        $actor = $request->user();
        if ($actor && $actor->mayMutateItems()) {
            return null;
        }

        $message = 'Your account cannot create, update, or delete items.';

        return $wantsJson
            ? response()->json(['message' => $message, 'ok' => false], 403)
            : redirect()->route('outfits.index')->with('error', $message);
    }

    public function transferStock(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! ($user->isManagement() || $user->isSuperAdmin())) {
            abort(403);
        }

        $validated = $request->validate([
            'outfit_code' => ['required', 'string', 'max:50'],
            'target_store_id' => ['required', 'integer', 'exists:stores,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $sourceStoreId = (int) ($request->session()->get('current_store_id') ?? 0);
        $targetStoreId = (int) $validated['target_store_id'];
        if ($sourceStoreId <= 0 || $sourceStoreId === $targetStoreId) {
            return back()->withErrors(['transfer' => 'Choose a different target store.']);
        }

        $code = trim((string) $validated['outfit_code']);
        $qty = (int) $validated['quantity'];

        DB::transaction(function () use ($sourceStoreId, $targetStoreId, $code, $qty): void {
            $from = Outfit::withoutGlobalScopes()
                ->where('store_id', $sourceStoreId)
                ->where('code', $code)
                ->lockForUpdate()
                ->firstOrFail();

            $to = Outfit::withoutGlobalScopes()
                ->where('store_id', $targetStoreId)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if ((int) $from->stock < $qty) {
                abort(422, 'Insufficient source stock.');
            }

            $from->stock = max(0, (int) $from->stock - $qty);
            $from->save();

            if (! $to) {
                $to = Outfit::withoutGlobalScopes()->create([
                    'store_id' => $targetStoreId,
                    'code' => $from->code,
                    'new_code' => $from->new_code,
                    'description' => $from->description,
                    'price' => $from->price,
                    'stock' => 0,
                    'qr_redirect_url' => $from->qr_redirect_url,
                ]);
            }

            $to->stock = ((int) $to->stock) + $qty;
            $to->save();
        });

        $targetStoreName = Store::query()->where('id', $targetStoreId)->value('name') ?? 'target store';

        return back()->with('success', "Transferred {$qty} stock of {$code} to {$targetStoreName}.");
    }

    private function rentalReturnBufferDays(): int
    {
        $default = (int) config('app.rental_return_buffer_days', 1);
        $bufferDays = (int) StoreCache::get('rental_return_buffer_days', $default);

        return max(0, $bufferDays);
    }

    private function normalizeDateParam(?string $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $raw)) {
            return $raw;
        }
        if (preg_match('/^(\\d{2})-(\\d{2})-(\\d{4})$/', $raw, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/^(\\d{2})\\/(\\d{2})\\/(\\d{4})$/', $raw, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return null;
    }

    private function isValidCalendarDate(string $date): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * True when every overlapping rental for these outfits is only a cool-down boundary touch
     * (same rules as RentalController::hasOnlyBoundaryCooldownConflicts).
     */
    private function hasOnlyBoundaryCooldownConflicts(array $outfitIds, string $startDate, string $endDate, ?int $excludeRentalId = null): bool
    {
        $bufferDays = $this->rentalReturnBufferDays();
        if ($bufferDays <= 0) {
            return false;
        }

        $requestedStart = Carbon::parse($this->normalizeDateParam($startDate) ?? $startDate)->startOfDay();
        $requestedEnd = Carbon::parse($this->normalizeDateParam($endDate) ?? $endDate)->startOfDay();
        $foundAnyConflicts = false;

        foreach ($outfitIds as $outfitId) {
            $outfitId = (int) $outfitId;
            $query = Rental::whereHas('outfits', fn ($q) => $q->where('outfits.id', $outfitId))
                ->where('status', 'active')
                ->where('start_date', '<=', $requestedEnd->copy()->addDays($bufferDays))
                ->where('end_date', '>=', $requestedStart->copy()->subDays($bufferDays));
            if ($excludeRentalId !== null) {
                $query->where('id', '!=', $excludeRentalId);
            }
            $rentals = $query->get();

            if (Schema::hasColumn('rentals', 'outfit_id')) {
                $fallback = Rental::where('outfit_id', '=', $outfitId, 'and')
                    ->where('status', 'active')
                    ->where('start_date', '<=', $requestedEnd->copy()->addDays($bufferDays))
                    ->where('end_date', '>=', $requestedStart->copy()->subDays($bufferDays));
                if ($excludeRentalId !== null) {
                    $fallback->where('id', '!=', $excludeRentalId);
                }
                $rentals = $rentals->merge($fallback->get())->unique('id')->values();
            }

            if ($rentals->isEmpty()) {
                continue;
            }

            $foundAnyConflicts = true;
            foreach ($rentals as $rental) {
                $boundaryForward = $rental->end_date->copy()->addDays($bufferDays)->isSameDay($requestedStart);
                $boundaryBackward = $rental->start_date->copy()->subDays($bufferDays)->isSameDay($requestedEnd);
                if (! ($boundaryForward || $boundaryBackward)) {
                    return false;
                }
            }
        }

        return $foundAnyConflicts;
    }

    public function index(): View
    {
        $insightsEnabled = request()->boolean('insights', false);
        $hasLegacyOutfitId = Schema::hasColumn('rentals', 'outfit_id');

        $decorateOutfit = static function (Outfit $outfit) use ($hasLegacyOutfitId): Outfit {
            if ($hasLegacyOutfitId) {
                $legacyOnly = Rental::query()
                    ->where('outfit_id', $outfit->id)
                    ->where('rentals.status', 'active')
                    ->whereNotExists(function ($q) use ($outfit): void {
                        $q->selectRaw('1')
                            ->from('rental_outfit')
                            ->whereColumn('rental_outfit.rental_id', 'rentals.id')
                            ->where('rental_outfit.outfit_id', $outfit->id);
                    })
                    ->count();
                $outfit->active_rentals_count = (int) ($outfit->active_rentals_count ?? 0) + $legacyOnly;
            }

            $scanUrl = route('outfits.scan', ['token' => $outfit->qr_token], absolute: true);
            $outfit->status_url = $scanUrl;
            $outfit->qr_image_url = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data='.urlencode($scanUrl);

            return $outfit;
        };

        $outfits = Outfit::query()
            ->select(['id', 'code', 'new_code', 'description', 'stock', 'qr_token', 'qr_redirect_url', 'price'])
            ->withCount(['activeRentals as active_rentals_count'])
            ->orderByDesc('id')
            ->paginate(250)
            ->withQueryString();

        $outfits->setCollection($outfits->getCollection()->map($decorateOutfit));

        $duplicateCodePanels = [];
        $outfitDuplicateSlugById = [];
        $skippedNumberPanels = [];
        $formatOutlierPanels = [];
        $duplicatePanelsJsonEncoded = '{}';

        if ($insightsEnabled) {
            $allOutfits = Outfit::query()
                ->select(['id', 'code', 'new_code', 'description', 'stock', 'qr_token', 'qr_redirect_url', 'price'])
                ->withCount(['activeRentals as active_rentals_count'])
                ->orderBy('code')
                ->get()
                ->map($decorateOutfit);

            $duplicateCodePanels = OutfitDuplicateCodeGroups::panelsFromOutfits($allOutfits);
            $outfitDuplicateSlugById = OutfitDuplicateCodeGroups::outfitIdToSlugMap($duplicateCodePanels);
            $skippedNumberPanels = OutfitSkippedNumberSequence::panelsFromOutfits($allOutfits);
            $formatOutlierPanels = OutfitPrefixFormatOutliers::panelsFromOutfits($allOutfits);

            $pricingEnabled = (bool) StoreCache::get('pricing_enabled', true);
            $outfitsById = $allOutfits->keyBy('id');
            foreach ($duplicateCodePanels as $i => $panel) {
                $editRows = [];
                $availabilityRows = [];
                foreach ($panel['outfit_ids'] as $sid) {
                    $s = $outfitsById->get($sid);
                    if (! $s) {
                        continue;
                    }
                    $activeRentals = (int) ($s->active_rentals_count ?? 0);
                    $availabilityRows[] = [
                        'code' => (string) ($s->code ?? ''),
                        'active_rentals' => $activeRentals,
                    ];
                    $row = [
                        'id' => $s->id,
                        'code' => (string) ($s->code ?? ''),
                        'new_code' => (string) ($s->new_code ?? ''),
                        'description' => (string) ($s->description ?? ''),
                        'stock' => (int) ($s->stock ?? 1),
                        'qr_redirect_url' => (string) ($s->qr_redirect_url ?? ''),
                        'active_rentals' => $activeRentals,
                    ];
                    if ($pricingEnabled) {
                        $row['price'] = $s->price !== null ? (string) $s->price : '';
                    }
                    $editRows[] = $row;
                }
                usort($availabilityRows, fn ($a, $b) => strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? '')));
                $duplicateCodePanels[$i]['availability_rows'] = $availabilityRows;
                $duplicateCodePanels[$i]['edit_rows'] = $editRows;
            }

            $duplicatePanelsForJs = [];
            foreach ($duplicateCodePanels as $panel) {
                $duplicatePanelsForJs[$panel['slug']] = [
                    'label' => $panel['label'],
                    'edit_rows' => $panel['edit_rows'] ?? [],
                ];
            }

            $duplicatePanelsJsonEncoded = json_encode(
                (object) $duplicatePanelsForJs,
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }

        return view('outfits.index', compact(
            'outfits',
            'duplicateCodePanels',
            'duplicatePanelsJsonEncoded',
            'outfitDuplicateSlugById',
            'skippedNumberPanels',
            'formatOutlierPanels'
        ));
    }

    /**
     * High-resolution PNG for printing; filename is derived from item code.
     */
    public function qrPngDownload(Outfit $outfit): Response
    {
        try {
            $body = $this->fetchPrintQrPngBody($outfit);
        } catch (\Throwable) {
            abort(502, 'Could not generate QR image. Try again in a moment.');
        }

        $filename = $this->safeQrDownloadBasename($outfit).'.png';

        return response($body, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /**
     * ZIP of print-resolution QR PNGs for every item (same 800×800 source as single download).
     */
    public function qrExportZip(): BinaryFileResponse|RedirectResponse
    {
        $outfits = Outfit::query()->orderBy('code', 'asc')->get();
        if ($outfits->isEmpty()) {
            return redirect()->route('outfits.index')->with('error', 'No items to export.');
        }

        @set_time_limit((int) max(120, $outfits->count() * 5));

        $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'outfit_qr_'.uniqid('', true).'.zip';
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Could not create export archive.');
        }

        $usedBasenames = [];
        try {
            foreach ($outfits as $outfit) {
                $body = $this->fetchPrintQrPngBody($outfit);
                $entry = $this->qrZipEntryFilename($outfit, $usedBasenames);
                if (! $zip->addFromString($entry, $body)) {
                    throw new \RuntimeException('zip add failed');
                }
            }
        } catch (\Throwable) {
            $zip->close();
            @unlink($zipPath);
            abort(502, 'Could not generate all QR images. Try again shortly.');
        }

        if (! $zip->close()) {
            @unlink($zipPath);
            abort(500, 'Could not finalize export archive.');
        }

        $downloadName = 'item-qr-codes-'.now()->format('Y-m-d').'.zip';

        return response()->download($zipPath, $downloadName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @throws \Throwable
     */
    private function fetchPrintQrPngBody(Outfit $outfit): string
    {
        $scanUrl = route('outfits.scan', ['token' => $outfit->qr_token], absolute: true);
        $pixels = 800;
        $apiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size='.$pixels.'x'.$pixels.'&data='.urlencode($scanUrl);

        $remote = Http::timeout(20)
            ->withHeaders(['Accept' => 'image/png'])
            ->get($apiUrl);

        if (! $remote->successful()) {
            throw new \RuntimeException('QR HTTP failed');
        }

        $body = $remote->body();
        if ($body === '' || ! str_starts_with($body, "\x89PNG")) {
            throw new \RuntimeException('Invalid PNG');
        }

        return $body;
    }

    /**
     * @param  array<string, true>  $usedBasenames
     */
    private function qrZipEntryFilename(Outfit $outfit, array &$usedBasenames): string
    {
        $base = $this->safeQrDownloadBasename($outfit);
        $candidate = $base.'.png';
        if (! isset($usedBasenames[$candidate])) {
            $usedBasenames[$candidate] = true;

            return $candidate;
        }
        $candidate = $base.'-'.$outfit->id.'.png';
        if (! isset($usedBasenames[$candidate])) {
            $usedBasenames[$candidate] = true;

            return $candidate;
        }
        $i = 2;
        do {
            $candidate = $base.'-'.$outfit->id.'-'.$i.'.png';
            $i++;
        } while (isset($usedBasenames[$candidate]));
        $usedBasenames[$candidate] = true;

        return $candidate;
    }

    private function safeQrDownloadBasename(Outfit $outfit): string
    {
        $raw = (string) ($outfit->code ?? '');
        $raw = trim($raw);
        if ($raw === '') {
            return 'item-'.$outfit->id;
        }

        $safe = preg_replace('/[^A-Za-z0-9._-]+/u', '_', $raw);
        $safe = trim((string) $safe, '._-');

        return $safe !== '' ? $safe : 'item-'.$outfit->id;
    }

    /**
     * Public scan target for printed QR codes (stable token; optional redirect per item).
     */
    public function scanByToken(string $token): View|RedirectResponse
    {
        $outfit = Outfit::query()->where('qr_token', $token)->firstOrFail();
        $target = $outfit->qr_redirect_url;
        if (is_string($target) && trim($target) !== '' && $this->isSafeHttpUrl(trim($target))) {
            return redirect()->away(trim($target));
        }

        return $this->renderItemStatus($outfit);
    }

    public function status(Request $request, Outfit $outfit): View
    {
        abort_unless($request->hasValidSignature(false), 401);

        return $this->renderItemStatus($outfit);
    }

    private function renderItemStatus(Outfit $outfit): View
    {
        $activeRentals = $outfit->rentals()
            ->where('status', 'active')
            ->with('handledBy')
            ->orderBy('start_date')
            ->get();

        $stock = max(0, (int) ($outfit->stock ?? 1));
        $activeCount = $activeRentals->count();
        $availableStock = max(0, $stock - $activeCount);

        // Lets a staff member mark this exact item right from its own QR
        // landing page — scanned via the phone's own camera/Google Lens
        // rather than the in-app scanner on the Stock Opname page — without
        // opening the full item list at all. Recognizes either a real
        // logged-in session OR a verified opname PIN (StockOpnamePin) —
        // the PIN exists specifically so this doesn't require a real
        // account password on a phone that isn't necessarily the staff
        // member's own. Computed only when one of those identifies a real
        // participant: this page is otherwise fully public (see
        // routes/web.php's /i/{token}), so nothing about an in-progress
        // opname is ever exposed to an anonymous visitor.
        $activeOpnameSession = null;
        $opnameItem = null;
        $opnameUser = Auth::user() ?: StockOpnamePin::verifiedUser();
        $opnameViaPin = ! Auth::check() && $opnameUser;
        if ($opnameUser && $opnameUser->mayParticipateInStockOpname()) {
            $activeOpnameSession = StockOpname::whereNull('completed_at')
                ->where('is_tutorial_practice', false)
                ->where('store_id', $outfit->store_id)
                ->with('startedBy')
                ->first();
            if ($activeOpnameSession) {
                $opnameItem = StockOpnameItem::where('stock_opname_id', $activeOpnameSession->id)
                    ->where('outfit_id', $outfit->id)
                    ->first();
            }
        } else {
            $opnameUser = null;
        }

        return view('outfits.status', compact(
            'outfit', 'activeRentals', 'stock', 'activeCount', 'availableStock',
            'activeOpnameSession', 'opnameItem', 'opnameUser', 'opnameViaPin'
        ));
    }

    /**
     * PIN entry on the public item page — see App\Support\StockOpnamePin's
     * own docblock for why this exists instead of just linking to /login.
     * Rate-limited per IP (routes/web.php) since a 6-digit PIN checked
     * against every eligible user is otherwise brute-forceable.
     */
    public function submitOpnamePin(Request $request, Outfit $outfit): RedirectResponse
    {
        $validated = $request->validate([
            'pin' => ['required', 'digits:6'],
        ]);

        // One shared budget across ALL IPs on top of the per-IP route throttle:
        // rotating addresses must not make a 6-digit PIN unlimited to guess.
        if (RateLimiter::tooManyAttempts('opname-pin-global', 60)) {
            return back()->withErrors(['pin' => 'Too many PIN attempts. Wait a few minutes and try again.']);
        }

        $user = StockOpnamePin::attempt($validated['pin']);
        if (! $user) {
            RateLimiter::hit('opname-pin-global', 600);

            return back()->withErrors(['pin' => 'Wrong PIN, or you are not at the store location.']);
        }

        // Same per-store login geofence as the real account login (see
        // AuthenticatedSessionController::passesStaffGeofence) — keyed to
        // THIS item's own store, not whichever store the user happens to
        // be assigned to, since the PIN gate is scoped to one scanned item.
        if (($user->isAdmin() || $user->isSupervisor())) {
            $lat = $request->filled('geo_lat') ? (float) $request->input('geo_lat') : null;
            $lng = $request->filled('geo_lng') ? (float) $request->input('geo_lng') : null;
            $accuracy = $request->filled('geo_accuracy') ? (float) $request->input('geo_accuracy') : null;
            if (! Geofence::passes($outfit->store_id, $lat, $lng, $accuracy)) {
                // Same wording as a wrong PIN: a different message would confirm the PIN is right.
                RateLimiter::hit('opname-pin-global', 600);

                return back()->withErrors(['pin' => 'Wrong PIN, or you are not at the store location. Enable location permission in your browser and try again.']);
            }
        }

        StockOpnamePin::verify($user, (int) $outfit->store_id);

        return redirect()->route('outfits.scan', $outfit->qr_token);
    }

    /**
     * "Not {name}?" — clears the PIN identity so the next person on a
     * shared device isn't marking items under someone else's name. Never
     * touches a real Auth::login() session; there isn't one here to clear.
     */
    public function logoutOpnamePin(Outfit $outfit): RedirectResponse
    {
        StockOpnamePin::forget();

        return redirect()->route('outfits.scan', $outfit->qr_token);
    }

    /**
     * Marks this exact outfit for whichever active opname session belongs to
     * its store — used only from the public item page, so identity comes
     * from either a real login or a verified PIN (never an outfit_id/session_id
     * supplied by the client, unlike StockOpnameController::mark(), since
     * a PIN-only visitor was never authenticated onto that page).
     */
    public function markViaOpnamePin(Request $request, Outfit $outfit): RedirectResponse
    {
        $user = Auth::user() ?: StockOpnamePin::verifiedUser();
        abort_unless($user && $user->mayParticipateInStockOpname(), 403);

        // This page only ever writes to REAL sessions of real outfits: a practice
        // (tutorial) outfit or user must not reach one.
        abort_if($outfit->is_tutorial || $user->is_tutorial, 404);

        // A PIN identity was unlocked (and geofenced) for ONE store's item page;
        // the outfit id in this URL is client-supplied, so it must be that store's.
        if (! Auth::user()) {
            abort_unless(StockOpnamePin::verifiedStoreId() === (int) $outfit->store_id, 403, 'This PIN is not valid for this store.');
        }

        $validated = $request->validate([
            'status' => ['required', 'in:ok,missing,damaged'],
        ]);

        $activeSession = StockOpname::whereNull('completed_at')
            ->where('is_tutorial_practice', false)
            ->where('store_id', $outfit->store_id)
            ->first();
        abort_unless($activeSession, 404, 'There is no stock opname session in progress for this store.');

        $existingItem = StockOpnameItem::where('stock_opname_id', $activeSession->id)->where('outfit_id', $outfit->id)->first();

        // Same rule as StockOpnameController::mark(): "Damaged" needs photo evidence,
        // and this page cannot upload one — so it only works when one is already attached.
        if ($validated['status'] === 'damaged' && ! ($existingItem && $existingItem->photo_path)) {
            return back()->withErrors(['status' => 'A photo is required to mark an item as Damaged. Open the Stock Opname page from the menu to take the photo.']);
        }

        // An existing note/photo survives re-marking as Damaged/Missing; marking "Present"
        // clears both, like the main opname screen does.
        $attributes = ['status' => $validated['status'], 'marked_by_user_id' => $user->id];
        if ($validated['status'] === 'ok') {
            if ($existingItem && $existingItem->photo_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($existingItem->photo_path)) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($existingItem->photo_path);
            }
            $attributes['note'] = null;
            $attributes['photo_path'] = null;
        }
        StockOpnameItem::upsertSafely(
            ['stock_opname_id' => $activeSession->id, 'outfit_id' => $outfit->id],
            $attributes
        );

        return redirect()->route('outfits.scan', $outfit->qr_token);
    }

    private function isSafeHttpUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array(strtolower((string) $scheme), ['http', 'https'], true);
    }

    /**
     * Accept price as plain number or Indonesian-formatted string (thousands ".", decimals ",").
     */
    private function normalizePriceInputForValidation(Request $request): void
    {
        if (! StoreCache::get('pricing_enabled', true)) {
            return;
        }

        $raw = $request->input('price');
        if ($raw === null || $raw === '') {
            $request->merge(['price' => null]);

            return;
        }

        $s = trim((string) $raw);
        if ($s === '') {
            $request->merge(['price' => null]);

            return;
        }

        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);

        if ($s === '' || ! is_numeric($s)) {
            $request->merge(['price' => $request->input('price')]);

            return;
        }

        $request->merge(['price' => $s]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($resp = $this->rejectUnlessItemMutatorRedirect($request)) {
            return $resp;
        }

        $request->merge([
            'code' => $this->nullIfBlank($request->input('code')),
            'new_code' => $this->nullIfBlank($request->input('new_code')),
            'description' => $this->nullIfBlank($request->input('description')),
        ]);
        $this->normalizePriceInputForValidation($request);

        $pricingEnabled = StoreCache::get('pricing_enabled', true);
        $storeId = $this->effectiveStoreIdForValidation($request);
        $rules = [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('outfits', 'code')->where(fn ($query) => $query->where('store_id', $storeId)),
            ],
            'new_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('outfits', 'new_code')->where(fn ($query) => $query->where('store_id', $storeId)),
            ],
            'description' => 'nullable|string|max:255',
            'stock' => 'nullable|integer|min:0',
        ];
        if ($pricingEnabled) {
            $rules['price'] = 'nullable|numeric|min:0|max:999999999.99';
        }
        $validated = $request->validate($rules);
        $validated['stock'] = array_key_exists('stock', $validated) && $validated['stock'] !== null
            ? (int) $validated['stock']
            : 1;

        Outfit::create($validated);

        return redirect()->route('outfits.index')->with('success', 'Item added successfully.');
    }

    public function update(Request $request, Outfit $outfit): RedirectResponse
    {
        if ($resp = $this->rejectUnlessItemMutatorRedirect($request)) {
            return $resp;
        }

        $request->merge([
            'code' => $this->nullIfBlank($request->input('code')),
            'new_code' => $this->nullIfBlank($request->input('new_code')),
            'description' => $this->nullIfBlank($request->input('description')),
            'qr_redirect_url' => $this->nullIfBlank($request->input('qr_redirect_url')),
        ]);
        $this->normalizePriceInputForValidation($request);

        $pricingEnabled = StoreCache::get('pricing_enabled', true);
        $incomingCode = $request->input('code');
        $incomingNewCode = $request->input('new_code');

        $rules = [
            'code' => 'required|string|max:50',
            'new_code' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:255',
            'stock' => 'nullable|integer|min:0',
            'qr_redirect_url' => 'nullable|url|max:2048',
        ];
        if ($incomingCode !== $outfit->code) {
            $rules['code'] = [
                'required',
                'string',
                'max:50',
                Rule::unique('outfits', 'code')
                    ->where(fn ($query) => $query->where('store_id', $outfit->store_id))
                    ->ignore($outfit->id),
            ];
        }
        if ($incomingNewCode !== $outfit->new_code) {
            $rules['new_code'] = [
                'nullable',
                'string',
                'max:50',
                Rule::unique('outfits', 'new_code')
                    ->where(fn ($query) => $query->where('store_id', $outfit->store_id))
                    ->ignore($outfit->id),
            ];
        }
        if ($pricingEnabled) {
            $rules['price'] = 'nullable|numeric|min:0|max:999999999.99';
        }
        $validated = $request->validate($rules);
        $validated['stock'] = array_key_exists('stock', $validated) && $validated['stock'] !== null
            ? (int) $validated['stock']
            : ($outfit->stock ?? 1);

        $outfit->update($validated);

        return redirect()->route('outfits.index')->with('success', 'Outfit updated successfully.');
    }

    public function destroy(Request $request, Outfit $outfit): RedirectResponse|JsonResponse
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        $wantsJson = $request->expectsJson()
            || str_contains($accept, 'application/json')
            || str_contains($accept, '+json');

        if ($resp = $this->rejectUnlessItemMutator($request)) {
            return $resp;
        }

        if (! AdminDeleteRestriction::userMayDeleteRecords($request->user())) {
            $message = AdminDeleteRestriction::denyMessage();

            return $wantsJson
                ? response()->json(['message' => $message, 'ok' => false], 403)
                : redirect()->route('outfits.index')->with('error', $message);
        }

        if ($outfit->hasActiveRentalsBlockingDelete()) {
            $message = 'Cannot delete outfit with active rentals.';

            return $wantsJson
                ? response()->json(['message' => $message], 422)
                : redirect()->route('outfits.index')->with('error', $message);
        }

        Outfit::withoutGlobalScope('store_scope')->whereKey((int) $outfit->id)->delete();

        return $wantsJson
            ? response()->json(['ok' => true, 'message' => 'Item deleted successfully.'])
            : redirect()->route('outfits.index')->with('success', 'Outfit deleted successfully.');
    }

    public function bulkDelete(Request $request): RedirectResponse
    {
        if ($resp = $this->rejectUnlessItemMutatorRedirect($request)) {
            return $resp;
        }

        if (! AdminDeleteRestriction::userMayDeleteRecords($request->user())) {
            return redirect()->route('outfits.index')->with('error', AdminDeleteRestriction::denyMessage());
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $validated['ids'] ?? [])));
        $storeIds = $request->user()?->accessibleActiveStoreIds() ?? [];
        if ($ids === [] || $storeIds === []) {
            return redirect()->route('outfits.index')->with('error', 'No valid items selected.');
        }

        $outfits = Outfit::withoutGlobalScope('store_scope')
            ->whereIn('id', $ids)
            ->whereIn('store_id', $storeIds)
            ->get();

        $blockedCodes = [];
        $deleteIds = [];
        foreach ($outfits as $outfit) {
            if ($outfit->hasActiveRentalsBlockingDelete()) {
                $blockedCodes[] = (string) $outfit->code;
            } else {
                $deleteIds[] = (int) $outfit->id;
            }
        }

        if ($deleteIds !== []) {
            Outfit::withoutGlobalScope('store_scope')->whereIn('id', $deleteIds)->delete();
        }

        $selectedCount = count($ids);
        $deletedCount = count($deleteIds);
        $blockedCount = count($blockedCodes);
        $missingCount = max(0, $selectedCount - $outfits->count());

        if ($deletedCount === 0) {
            $msg = $blockedCount > 0
                ? 'No items deleted. Selected item(s) are linked to active rentals.'
                : 'No items deleted. Refresh and select valid rows again.';

            return redirect()->route('outfits.index')->with('error', $msg);
        }

        $msg = "{$deletedCount} item(s) deleted.";
        if ($blockedCount > 0) {
            $msg .= " {$blockedCount} blocked by active rentals: ".implode(', ', array_slice($blockedCodes, 0, 10));
            if ($blockedCount > 10) {
                $msg .= '...';
            }
        }
        if ($missingCount > 0) {
            $msg .= " {$missingCount} item(s) skipped (not found / not accessible).";
        }

        return redirect()->route('outfits.index')->with('success', $msg);
    }

    public function bulkStore(Request $request): RedirectResponse
    {
        if ($resp = $this->rejectUnlessItemMutatorRedirect($request)) {
            return $resp;
        }

        $request->validate([
            'codes' => 'required|string|max:10000',
        ]);

        $storeId = (int) (StoreContext::storeId() ?? $request->session()->get('current_store_id') ?? 0);
        if ($storeId <= 0) {
            $storeId = (int) (Store::query()->where('is_active', '=', true, 'and')->orderBy('id', 'asc')->value('id') ?? 0);
        }
        if ($storeId <= 0) {
            return redirect()->route('outfits.index')->with('error', 'No active store selected for import.');
        }

        $existingCodes = Outfit::pluck('code', null)->map(fn ($c) => strtoupper($c))->toArray();

        $lines = explode("\n", $request->codes);
        $added = [];
        $skipped = [];

        foreach ($lines as $line) {
            $code = strtoupper(trim($line));
            if ($code === '' || strlen($code) > 50) {
                continue;
            }
            if (in_array($code, $existingCodes) || in_array($code, $added)) {
                $skipped[] = $code;

                continue;
            }
            $added[] = $code;
        }

        if (empty($added)) {
            return redirect()->route('outfits.index')
                ->with('error', 'No new outfits imported. '.count($skipped).' code(s) already exist.');
        }

        $now = now();
        Outfit::insert(array_map(fn ($code) => [
            'store_id' => $storeId,
            'code' => $code,
            'stock' => 1,
            'qr_token' => Str::random(48),
            'created_at' => $now,
            'updated_at' => $now,
        ], $added));

        $msg = count($added).' outfit(s) added successfully.';
        if (! empty($skipped)) {
            $msg .= ' '.count($skipped).' code(s) skipped (already exist).';
        }

        return redirect()->route('outfits.index')->with('success', $msg);
    }

    public function bulkNewCode(Request $request): RedirectResponse|JsonResponse
    {
        $wantsJson = $request->expectsJson()
            || str_contains(strtolower((string) $request->header('Accept', '')), 'application/json');

        if ($resp = $this->rejectUnlessItemMutator($request)) {
            return $resp;
        }

        $request->validate([
            'pairs' => 'required|string|max:50000',
        ]);

        $lines = explode("\n", $request->pairs);
        $updated = 0;
        $notFound = [];
        $conflicts = [];

        foreach ($lines as $i => $line) {
            $lineNum = $i + 1;
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $parts = explode("\t", $trimmed);
            if (count($parts) < 2) {
                $conflicts[] = "Line {$lineNum}: put the current code, then Tab, then the new code (two columns).";

                continue;
            }

            $oldCode = trim($parts[0]);
            $newCode = trim($parts[1]);
            if ($oldCode === '' || $newCode === '') {
                $conflicts[] = "Line {$lineNum}: both current code and new code are required.";

                continue;
            }

            $outfit = Outfit::where('code', '=', $oldCode, 'and')->first();
            if (! $outfit) {
                $notFound[] = $oldCode;

                continue;
            }

            $duplicateNew = Outfit::query()
                ->where('store_id', $outfit->store_id)
                ->where('new_code', $newCode)
                ->where('id', '!=', $outfit->id)
                ->first();
            if ($duplicateNew) {
                $conflicts[] = "Line {$lineNum}: new code \"{$newCode}\" is already set on item \"{$duplicateNew->code}\".";

                continue;
            }

            $outfit->update(['new_code' => $newCode]);
            $updated++;
        }

        if ($updated === 0 && empty($notFound) && empty($conflicts)) {
            $msg = 'No valid pairs to apply. Add at least one row with a current code and a new code.';
            if ($wantsJson) {
                return response()->json([
                    'message' => $msg,
                    'errors' => ['pairs' => [$msg]],
                ], 422);
            }

            return redirect()->route('outfits.index')->withErrors(['pairs' => $msg]);
        }

        if ($updated === 0 && (! empty($notFound) || ! empty($conflicts))) {
            $linesOut = [];
            foreach ($notFound as $c) {
                $linesOut[] = 'No item with code: '.$c;
            }
            foreach ($conflicts as $c) {
                $linesOut[] = $c;
            }
            $flat = implode("\n", $linesOut);
            if ($wantsJson) {
                return response()->json([
                    'message' => 'No codes were updated.',
                    'errors' => ['pairs' => $linesOut],
                ], 422);
            }

            return redirect()->route('outfits.index')->withErrors(['pairs' => $flat]);
        }

        $msg = "{$updated} item(s) updated with new code.";
        if (! empty($notFound)) {
            $msg .= ' '.count($notFound).' code(s) not found: '.implode(', ', array_slice($notFound, 0, 10));
            if (count($notFound) > 10) {
                $msg .= '...';
            }
        }
        if (! empty($conflicts)) {
            $msg .= ' '.count($conflicts).' line(s) skipped: '.implode(' ', array_slice($conflicts, 0, 5));
            if (count($conflicts) > 5) {
                $msg .= ' …';
            }
        }

        if ($wantsJson) {
            return response()->json([
                'ok' => true,
                'message' => $msg,
                'warnings' => array_merge(
                    array_map(fn ($c) => 'Not found: '.$c, $notFound),
                    $conflicts
                ),
            ]);
        }

        return redirect()->route('outfits.index')->with('success', $msg);
    }

    public function available(Request $request): JsonResponse
    {
        $startDate = $this->normalizeDateParam($request->start) ?? $request->start;
        $endDate = $this->normalizeDateParam($request->end) ?? $request->end;
        // A digit-shaped but impossible date (e.g. "2026-13-40") would otherwise reach
        // Carbon::parse() below and throw — treat it as not provided instead of a 500.
        if ($startDate && ! $this->isValidCalendarDate($startDate)) {
            $startDate = null;
        }
        if ($endDate && ! $this->isValidCalendarDate($endDate)) {
            $endDate = null;
        }
        $excludeId = $request->exclude ? (int) $request->exclude : null;
        $bufferDays = $this->rentalReturnBufferDays();
        // Onboarding tour item search: real inventory is completely off-limits (a
        // practice rental attaching a real item would falsely block it for real
        // customers over that date range) — a fixed set of dedicated is_tutorial
        // items exists purely for the tour to search/select from instead.
        $tutorialMode = $request->boolean('tutorial');

        // Collect overlap counts per outfit for stock-aware availability.
        $activeOverlapCountByOutfit = [];

        if ($startDate && $endDate) {
            // Get all active rentals overlapping the date range
            $overlappingRentalsQuery = Rental::where('status', '=', 'active', 'and')
                ->where('start_date', '<=', Carbon::parse($endDate)->addDays($bufferDays))
                ->where('end_date', '>=', Carbon::parse($startDate)->subDays($bufferDays));
            if ($tutorialMode) {
                $overlappingRentalsQuery->withoutGlobalScope('tutorial_scope')->where('is_tutorial', true);
            }
            $overlappingRentals = $overlappingRentalsQuery->get();

            foreach ($overlappingRentals as $rental) {
                if ($excludeId !== null && (int) $rental->id === (int) $excludeId) {
                    continue;
                }

                // One "slot" per outfit per rental (pivot + legacy outfit_id may repeat the same outfit).
                $idsFromPivot = $rental->outfits()->pluck('outfits.id', null)->map(fn ($id) => (int) $id)->all();
                if (Schema::hasColumn('rentals', 'outfit_id') && $rental->outfit_id) {
                    $idsFromPivot[] = (int) $rental->outfit_id;
                }
                $idsFromPivot = array_values(array_unique(array_filter($idsFromPivot)));

                foreach ($idsFromPivot as $sid) {
                    $activeOverlapCountByOutfit[$sid] = ($activeOverlapCountByOutfit[$sid] ?? 0) + 1;
                }
            }
        }

        $excludedRental = null;
        if ($excludeId !== null && $startDate && $endDate) {
            $excludedRentalQuery = Rental::query()->with('outfits');
            if ($tutorialMode) {
                $excludedRentalQuery->withoutGlobalScope('tutorial_scope');
            }
            $excludedRental = $excludedRentalQuery->find($excludeId);
        }

        $outfitsQuery = Outfit::orderBy('code', 'asc');
        if ($tutorialMode) {
            $outfitsQuery->withoutGlobalScope('tutorial_scope')->where('is_tutorial', true);
        }

        $outfits = $outfitsQuery->get()->map(function ($outfit) use ($activeOverlapCountByOutfit, $startDate, $endDate, $bufferDays, $excludeId, $excludedRental) {
            if ($excludedRental && $excludedRental->status === 'active' && $startDate && $endDate) {
                $reqStart = Carbon::parse($startDate)->toDateString();
                $reqEnd = Carbon::parse($endDate)->toDateString();
                if ($excludedRental->start_date->toDateString() === $reqStart
                    && $excludedRental->end_date->toDateString() === $reqEnd) {
                    $sid = (int) $outfit->id;
                    $onRental = $excludedRental->outfits->contains(fn ($s) => (int) $s->id === $sid);
                    if (Schema::hasColumn('rentals', 'outfit_id') && (int) ($excludedRental->outfit_id ?? 0) === $sid) {
                        $onRental = true;
                    }
                    if ($onRental) {
                        $stock = max(0, (int) ($outfit->stock ?? 1));

                        return [
                            'id' => $outfit->id,
                            'code' => $outfit->code,
                            'new_code' => $outfit->new_code,
                            'description' => $outfit->description,
                            'price' => StoreCache::get('pricing_enabled', true) ? $outfit->price : null,
                            'stock' => $stock,
                            'available' => true,
                            'status_html' => view('outfits.partials.status-cell', [
                                'available' => true,
                                'code' => $outfit->code,
                            ])->render(),
                            'selectable' => true,
                            'needs_manager_approval' => false,
                            'rental' => null,
                        ];
                    }
                }
            }

            $activeOverlap = (int) ($activeOverlapCountByOutfit[$outfit->id] ?? 0);
            $stock = max(0, (int) ($outfit->stock ?? 1));
            $available = $activeOverlap < max(1, $stock);
            $conflict = ! $available;
            $rentalInfo = null;

            if ($conflict && $startDate && $endDate) {
                $rental = Rental::whereHas('outfits', fn ($q) => $q->where('outfits.id', $outfit->id))
                    ->where('status', 'active')
                    ->where('start_date', '<=', Carbon::parse($endDate)->addDays($bufferDays))
                    ->where('end_date', '>=', Carbon::parse($startDate)->subDays($bufferDays))
                    ->first();

                if (! $rental && Schema::hasColumn('rentals', 'outfit_id')) {
                    $rental = Rental::where('outfit_id', '=', $outfit->id, 'and')
                        ->where('status', 'active')
                        ->where('start_date', '<=', Carbon::parse($endDate)->addDays($bufferDays))
                        ->where('end_date', '>=', Carbon::parse($startDate)->subDays($bufferDays))
                        ->first();
                }

                if ($rental) {
                    $rentalInfo = [
                        'id' => $rental->id,
                        'customer' => $rental->customer_name,
                        'start' => $rental->start_date->format('d M Y'),
                        'end' => $rental->end_date->format('d M Y'),
                    ];
                }
            }

            $needsManagerApproval = ! $available && $startDate && $endDate
                && $this->hasOnlyBoundaryCooldownConflicts([(int) $outfit->id], $startDate, $endDate, $excludeId);
            $selectable = $available || $needsManagerApproval;

            return [
                'id' => $outfit->id,
                'code' => $outfit->code,
                'new_code' => $outfit->new_code,
                'description' => $outfit->description,
                'price' => StoreCache::get('pricing_enabled', true) ? $outfit->price : null,
                'stock' => $stock,
                'available' => $available,
                'status_html' => view('outfits.partials.status-cell', [
                    'available' => $available,
                    'code' => $outfit->code,
                ])->render(),
                'selectable' => $selectable,
                'needs_manager_approval' => $needsManagerApproval,
                'rental' => $rentalInfo,
            ];
        });

        return response()
            ->json(['outfits' => $outfits])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function quickAdd(Request $request): JsonResponse
    {
        if ($resp = $this->rejectUnlessItemMutator($request)) {
            return $resp instanceof JsonResponse
                ? $resp
                : response()->json(['message' => 'Your account cannot create, update, or delete items.', 'ok' => false], 403);
        }

        $request->merge([
            'new_code' => $this->nullIfBlank($request->input('new_code')),
            'description' => $this->nullIfBlank($request->input('description')),
        ]);

        $storeId = $this->effectiveStoreIdForValidation($request);
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('outfits', 'code')->where(fn ($query) => $query->where('store_id', $storeId)),
            ],
            'new_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('outfits', 'new_code')->where(fn ($query) => $query->where('store_id', $storeId)),
            ],
            'description' => 'nullable|string|max:255',
        ]);

        $payload = [
            'code' => $validated['code'],
            'description' => $validated['description'] ?? '',
            'stock' => 1,
        ];
        if (! empty($validated['new_code'] ?? '')) {
            $payload['new_code'] = $validated['new_code'];
        }

        $outfit = Outfit::create($payload);

        return response()->json(['outfit' => $outfit]);
    }

    public function bulkPrice(Request $request): RedirectResponse
    {
        if ($resp = $this->rejectUnlessItemMutatorRedirect($request)) {
            return $resp;
        }

        if (! StoreCache::get('pricing_enabled', true)) {
            return redirect()->route('outfits.index')->with('error', 'Pricing is disabled.');
        }

        $request->validate([
            'pairs' => 'required|string|max:50000',
        ]);

        $lines = explode("\n", $request->pairs);
        $updated = 0;
        $notFound = [];

        foreach ($lines as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) < 2) {
                continue;
            }
            $code = trim($parts[0]);
            $price = trim($parts[1]);
            if ($code === '' || $price === '' || ! is_numeric($price)) {
                continue;
            }

            $outfit = Outfit::where('code', '=', $code, 'and')->first();
            if ($outfit) {
                $outfit->update(['price' => $price]);
                $updated++;
            } else {
                $notFound[] = $code;
            }
        }

        $msg = "{$updated} item(s) price updated.";
        if (! empty($notFound)) {
            $msg .= ' '.count($notFound).' code(s) not found: '.implode(', ', array_slice($notFound, 0, 10));
            if (count($notFound) > 10) {
                $msg .= '...';
            }
        }

        return redirect()->route('outfits.index')->with('success', $msg);
    }

    public function bulkDiscount(Request $request): RedirectResponse
    {
        if ($resp = $this->rejectUnlessItemMutatorRedirect($request)) {
            return $resp;
        }

        if (! StoreCache::get('pricing_enabled', true)) {
            return redirect()->route('outfits.index')->with('error', 'Pricing is disabled.');
        }

        $request->validate([
            'codes' => 'required|string|max:50000',
            'type' => 'required|in:percent,fixed',
            'amount' => 'required|numeric|min:0',
        ]);

        $codes = array_filter(array_map('trim', explode("\n", $request->codes)));
        $updated = 0;
        $notFound = [];

        foreach ($codes as $code) {
            if ($code === '') {
                continue;
            }
            $outfit = Outfit::where('code', '=', $code, 'and')->first();
            if ($outfit) {
                $price = $outfit->price;
                if ($request->type === 'percent') {
                    $price = $price - ($price * $request->amount / 100);
                } else {
                    $price = $price - $request->amount;
                }
                $outfit->update(['price' => max(0, $price)]);
                $updated++;
            } else {
                $notFound[] = $code;
            }
        }

        $msg = "{$updated} item(s) discount applied.";
        if (! empty($notFound)) {
            $msg .= ' '.count($notFound).' code(s) not found: '.implode(', ', array_slice($notFound, 0, 10));
            if (count($notFound) > 10) {
                $msg .= '...';
            }
        }

        return redirect()->route('outfits.index')->with('success', $msg);
    }

    public function bulkUpdate(Request $request): RedirectResponse
    {
        if ($resp = $this->rejectUnlessItemMutatorRedirect($request)) {
            return $resp;
        }

        $pricingEnabled = (bool) StoreCache::get('pricing_enabled', true);

        $items = $request->input('items', []);
        if (is_array($items)) {
            foreach ($items as $k => $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (array_key_exists('stock', $row) && $row['stock'] === '') {
                    unset($items[$k]['stock']);
                }
            }
            $request->merge(['items' => array_values($items)]);
        }

        $rules = [
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer',
            'items.*.code' => 'nullable|string|max:50',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.stock' => 'nullable|integer|min:0',
            'items.*.new_code' => 'nullable|string|max:50',
            'items.*.qr_redirect_url' => 'nullable|string|max:2048',
        ];
        if ($pricingEnabled) {
            $rules['items.*.price'] = 'nullable|string|max:60';
        }

        $validated = $request->validate($rules);

        $updated = 0;

        try {
            DB::transaction(function () use (&$updated, $validated, $pricingEnabled, $request): void {
                foreach ($validated['items'] as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    $actor = $request->user();
                    $storeIds = $actor ? $actor->accessibleActiveStoreIds() : [];
                    $outfit = $storeIds === []
                        ? null
                        : Outfit::withoutGlobalScope('store_scope')
                            ->whereKey($id)
                            ->whereIn('store_id', $storeIds)
                            ->first();
                    if (! $outfit) {
                        throw new \RuntimeException('invalid_item');
                    }

                    $incomingCode = $this->nullIfBlank($row['code'] ?? null);
                    if ($incomingCode === null || $incomingCode === '') {
                        $incomingCode = (string) ($outfit->code ?? '');
                    }
                    if ($incomingCode !== $outfit->code) {
                        Validator::make(
                            ['code' => $incomingCode],
                            [
                                'code' => [
                                    'nullable',
                                    'string',
                                    'max:50',
                                    Rule::unique('outfits', 'code')
                                        ->where(fn ($query) => $query->where('store_id', $outfit->store_id))
                                        ->ignore($outfit->id),
                                ],
                            ]
                        )->validate();
                    }

                    $incomingNew = $this->nullIfBlank($row['new_code'] ?? null);
                    if ($incomingNew !== $outfit->new_code) {
                        Validator::make(
                            ['new_code' => $incomingNew],
                            [
                                'new_code' => [
                                    'nullable',
                                    'string',
                                    'max:50',
                                    Rule::unique('outfits', 'new_code')
                                        ->where(fn ($query) => $query->where('store_id', $outfit->store_id))
                                        ->ignore($outfit->id),
                                ],
                            ]
                        )->validate();
                    }

                    $rawUrl = $this->nullIfBlank($row['qr_redirect_url'] ?? null);
                    if ($rawUrl !== null && ! $this->isSafeHttpUrl((string) $rawUrl)) {
                        throw new \InvalidArgumentException('bad_qr_url');
                    }

                    $payload = [
                        'code' => $incomingCode,
                        'description' => $this->nullIfBlank($row['description'] ?? null),
                        'new_code' => $incomingNew,
                        'qr_redirect_url' => $rawUrl,
                        'stock' => array_key_exists('stock', $row) && $row['stock'] !== null && $row['stock'] !== ''
                            ? (int) $row['stock']
                            : (int) ($outfit->stock ?? 1),
                    ];

                    if ($pricingEnabled) {
                        $payload['price'] = $this->parseBulkPriceToNullable($row['price'] ?? null);
                    }

                    $outfit->update($payload);
                    $updated++;
                }
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException) {
            return redirect()->route('outfits.index')->with('error', 'QR redirect must be a valid http(s) URL.');
        } catch (\Throwable) {
            return redirect()->route('outfits.index')->with('error', 'One or more items could not be updated. Refresh and try again.');
        }

        return redirect()->route('outfits.index')->with('success', "Updated {$updated} item(s).");
    }

    public function applyTaxToPrices(Request $request): RedirectResponse
    {
        if ($resp = $this->rejectUnlessItemMutatorRedirect($request)) {
            return $resp;
        }

        if (! StoreCache::get('pricing_enabled', true)) {
            return redirect()->route('outfits.index')->with('error', 'Pricing is disabled.');
        }

        if (! Schema::hasColumn('rental_outfit', 'price_incl_tax')
            || ! Schema::hasColumn('rental_outfit', 'price_excl_tax')
            || ! Schema::hasColumn('rental_outfit', 'tax_amount')) {
            return redirect()->route('outfits.index')->with('error', 'Tax columns are missing on rental_outfit. Run migrations first.');
        }

        $taxPercent = max(0.0, (float) StoreCache::get('pricing_tax_percent', 0));
        $rate = $taxPercent / 100;

        $actor = $request->user();
        $storeIds = $actor ? $actor->accessibleActiveStoreIds() : [];
        if ($storeIds === []) {
            return redirect()->route('outfits.index')->with('error', 'No accessible stores.');
        }
        $targetStoreIds = [];
        if (StoreContext::allStores()) {
            $targetStoreIds = $storeIds;
        } else {
            $storeId = StoreContext::storeId();
            if (! $storeId) {
                $storeId = (int) ($request->session()->get('current_store_id') ?? 0);
            }
            if (! $storeId || ! in_array($storeId, $storeIds, true)) {
                return redirect()->route('outfits.index')->with('error', 'No active store selected.');
            }
            $targetStoreIds = [$storeId];
        }

        try {
            $affected = DB::transaction(function () use ($targetStoreIds, $rate): int {
                $total = 0;
                foreach ($targetStoreIds as $storeId) {
                    $total += DB::update(
                        // rs.price_incl_tax is tax-inclusive, so the excl-tax
                        // price is the whole amount divided by (1+rate) — not
                        // the inclusive price minus (inclusive price * rate),
                        // which double-counts tax on top of tax. Matches the
                        // same fix in RentalFinancialSummary::summarize().
                        'UPDATE rental_outfit rs
                            JOIN rentals r ON r.id = rs.rental_id
                            JOIN outfits s ON s.id = rs.outfit_id
                            SET rs.price_incl_tax = IFNULL(rs.price_incl_tax, s.price),
                                rs.price_excl_tax = ROUND(IFNULL(rs.price_incl_tax, s.price) / (1 + ?), 2),
                                rs.tax_amount = ROUND(IFNULL(rs.price_incl_tax, s.price) - (IFNULL(rs.price_incl_tax, s.price) / (1 + ?)), 2)
                            WHERE r.store_id = ? AND s.store_id = ? AND s.price IS NOT NULL',
                        [(float) $rate, (float) $rate, $storeId, $storeId],
                    );
                }

                StoreCache::forever('pricing_prices_include_tax', true);

                return (int) $total;
            });
        } catch (\Throwable) {
            return redirect()->route('outfits.index')->with('error', 'Could not update item prices. Refresh and try again.');
        }

        $msg = "Synced rental tax breakdown for {$affected} rental item row(s).";

        return redirect()->route('outfits.index')->with('success', $msg);
    }

    public function syncRentalOutfitTax(Request $request): RedirectResponse
    {
        if ($resp = $this->rejectUnlessItemMutatorRedirect($request)) {
            return $resp;
        }

        if (! StoreCache::get('pricing_enabled', true)) {
            return redirect()->route('outfits.index')->with('error', 'Pricing is disabled.');
        }

        if (! Schema::hasColumn('rental_outfit', 'price_incl_tax')
            || ! Schema::hasColumn('rental_outfit', 'price_excl_tax')
            || ! Schema::hasColumn('rental_outfit', 'tax_amount')) {
            return redirect()->route('outfits.index')->with('error', 'Tax columns are missing on rental_outfit. Run migrations first.');
        }

        $taxPercent = max(0.0, (float) StoreCache::get('pricing_tax_percent', 0));
        $rate = $taxPercent / 100;

        $actor = $request->user();
        $storeIds = $actor ? $actor->accessibleActiveStoreIds() : [];
        if ($storeIds === []) {
            return redirect()->route('outfits.index')->with('error', 'No accessible stores.');
        }
        $targetStoreIds = [];
        if (StoreContext::allStores()) {
            $targetStoreIds = $storeIds;
        } else {
            $storeId = StoreContext::storeId();
            if (! $storeId) {
                $storeId = (int) ($request->session()->get('current_store_id') ?? 0);
            }
            if (! $storeId || ! in_array($storeId, $storeIds, true)) {
                return redirect()->route('outfits.index')->with('error', 'No active store selected.');
            }
            $targetStoreIds = [$storeId];
        }

        try {
            $matched = (int) DB::table('rental_outfit as rs')
                ->join('rentals as r', 'r.id', '=', 'rs.rental_id')
                ->join('outfits as s', 's.id', '=', 'rs.outfit_id')
                ->whereIn('r.store_id', $targetStoreIds)
                ->whereColumn('s.store_id', 'r.store_id')
                ->count('*');

            $affected = DB::transaction(function () use ($targetStoreIds, $rate): int {
                $total = 0;
                foreach ($targetStoreIds as $storeId) {
                    $total += DB::update(
                        // rs.price_incl_tax is tax-inclusive, so the excl-tax
                        // price is the whole amount divided by (1+rate) — not
                        // the inclusive price minus (inclusive price * rate),
                        // which double-counts tax on top of tax. Matches the
                        // same fix in RentalFinancialSummary::summarize().
                        'UPDATE rental_outfit rs
                            JOIN rentals r ON r.id = rs.rental_id
                            JOIN outfits s ON s.id = rs.outfit_id
                            SET rs.price_incl_tax = IFNULL(rs.price_incl_tax, s.price),
                                rs.price_excl_tax = ROUND(IFNULL(rs.price_incl_tax, s.price) / (1 + ?), 2),
                                rs.tax_amount = ROUND(IFNULL(rs.price_incl_tax, s.price) - (IFNULL(rs.price_incl_tax, s.price) / (1 + ?)), 2)
                            WHERE r.store_id = ? AND s.store_id = ? AND s.price IS NOT NULL',
                        [(float) $rate, (float) $rate, $storeId, $storeId],
                    );
                }
                return (int) $total;
            });
        } catch (\Throwable) {
            return redirect()->route('outfits.index')->with('error', 'Could not sync rental tax fields. Refresh and try again.');
        }

        StoreCache::forever('pricing_prices_include_tax', true);

        if ($matched < 1) {
            return redirect()->route('outfits.index')->with('success', 'No rental items found to sync for the selected store scope.');
        }

        if ($affected < 1) {
            return redirect()->route('outfits.index')->with('success', "Rental tax fields are already synced ({$matched} row(s) checked).");
        }

        return redirect()->route('outfits.index')->with('success', "Synced tax fields for {$affected} rental item row(s) ({$matched} row(s) checked).");
    }

    /**
     * Live uniqueness check for bulk-edit rows (same rules as {@see bulkUpdate}).
     */
    public function uniqueCheckForBulkEdit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outfit_id' => 'required|integer',
            'field' => 'required|in:code,new_code',
            'value' => 'nullable|string|max:50',
        ]);

        $user = $request->user();
        $storeIds = $user ? $user->accessibleActiveStoreIds() : [];
        $outfit = $storeIds === []
            ? null
            : Outfit::withoutGlobalScope('store_scope')
                ->whereKey((int) $validated['outfit_id'])
                ->whereIn('store_id', $storeIds)
                ->first();
        if (! $outfit) {
            return response()->json([
                'available' => false,
                'message' => 'Item not found.',
            ], 404);
        }

        $raw = $validated['value'] ?? null;

        if ($validated['field'] === 'code') {
            $incomingCode = $this->nullIfBlank($raw);
            if ($incomingCode === null || $incomingCode === '') {
                $incomingCode = (string) ($outfit->code ?? '');
            }
            if ($incomingCode === (string) ($outfit->code ?? '')) {
                return response()->json(['available' => true]);
            }

            $validator = Validator::make(
                ['code' => $incomingCode],
                [
                    'code' => [
                        'nullable',
                        'string',
                        'max:50',
                        Rule::unique('outfits', 'code')
                            ->where(fn ($query) => $query->where('store_id', $outfit->store_id))
                            ->ignore($outfit->id),
                    ],
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'available' => false,
                    'message' => $validator->errors()->first('code'),
                ]);
            }

            return response()->json(['available' => true]);
        }

        $incomingNew = $this->nullIfBlank($raw);
        if ($incomingNew !== $outfit->new_code) {
            $validator = Validator::make(
                ['new_code' => $incomingNew],
                [
                    'new_code' => [
                        'nullable',
                        'string',
                        'max:50',
                        Rule::unique('outfits', 'new_code')
                            ->where(fn ($query) => $query->where('store_id', $outfit->store_id))
                            ->ignore($outfit->id),
                    ],
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'available' => false,
                    'message' => $validator->errors()->first('new_code'),
                ]);
            }
        }

        return response()->json(['available' => true]);
    }

    private function parseBulkPriceToNullable(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }

        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);

        if ($s === '' || ! is_numeric($s)) {
            return null;
        }

        $n = (float) $s;

        return max(0.0, min(999999999.99, $n));
    }

    private function nullIfBlank(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Align validation with DB uniqueness (store_id + code / new_code).
     */
    private function effectiveStoreIdForValidation(Request $request): int
    {
        $storeId = StoreContext::storeId();
        if ($storeId !== null && $storeId > 0) {
            return $storeId;
        }

        $sessionId = (int) ($request->session()->get('current_store_id') ?? 0);
        if ($sessionId > 0) {
            return $sessionId;
        }

        return (int) (Store::query()->orderBy('id', 'asc')->value('id') ?? 0);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\Outfit;
use App\Support\StoreContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Dompdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StockOpnameController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->mayParticipateInStockOpname(), 403);

        $sessions = StockOpname::with(['startedBy', 'completedBy'])
            ->where('is_tutorial_practice', false)
            ->withCount(['items as exceptions_count' => function ($query) {
                $query->whereIn('status', ['missing', 'damaged']);
            }])
            ->orderByDesc('started_at')
            ->paginate(15);

        $activeSession = StockOpname::whereNull('completed_at')
            ->where('is_tutorial_practice', false)
            ->latest('started_at')
            ->first();

        return view('stock-opname.index', [
            'sessions' => $sessions,
            'activeSession' => $activeSession,
            'mayManage' => $user->mayManageStockOpname(),
            'storeLabel' => StoreContext::allStores() ? 'All Stores' : 'Current Store',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->mayManageStockOpname(), 403);

        $storeId = StoreContext::storeId();
        // storeId() still returns the last selected store while the "all stores"
        // toggle is on — that mode must not silently start a session for one of them.
        if (! $storeId || StoreContext::allStores()) {
            return back()->withErrors('Select a single store in the sidebar before starting a stock opname — a stock opname is counted per store, not for "all stores" at once.');
        }

        // A double click (or two supervisors at once) must not open two sessions.
        $lock = Cache::lock("stock-opname-start-{$storeId}", 10);
        if (! $lock->get()) {
            return back()->withErrors('The stock opname session is being created, please wait a moment.');
        }
        try {
            if (StockOpname::withoutGlobalScope('store_scope')->where('store_id', $storeId)->whereNull('completed_at')->where('is_tutorial_practice', false)->exists()) {
                return back()->withErrors('This store still has an unfinished stock opname session — finish it before starting a new one.');
            }

            $session = StockOpname::create([
                'store_id' => $storeId,
                'started_by_user_id' => $user->id,
                'started_at' => now(),
            ]);
        } finally {
            $lock->release();
        }

        return redirect()->route('stock-opname.show', $session)->with('success', 'Stock opname session started.');
    }

    /**
     * Starts a private, tutorial-only session for the interactive walkthrough —
     * a real StockOpname row (so the tour can drive the real show page/buttons),
     * scoped to only the store's is_tutorial=true practice Outfits instead of its
     * real inventory, and tagged is_tutorial_practice so index()/store() ignore
     * it entirely (never blocks, or is blocked by, a real session).
     */
    public function startTutorial(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless($user->mayParticipateInStockOpname(), 403);

        $storeId = StoreContext::storeId();
        if (! $storeId) {
            return response()->json(['ok' => false, 'error' => 'Select a single store in the sidebar to try this practice.'], 422);
        }

        // Only one practice session per user at a time — starting fresh
        // discards whatever the user was previously trying, so re-entering
        // the tour (or clicking Back past this step and forward again)
        // never piles up stray practice sessions.
        StockOpname::where('started_by_user_id', $user->id)
            ->where('is_tutorial_practice', true)
            ->get()
            ->each->delete();

        $session = StockOpname::create([
            'store_id' => $storeId,
            'started_by_user_id' => $user->id,
            'started_at' => now(),
            'is_tutorial_practice' => true,
        ]);

        return response()->json(['ok' => true, 'redirect' => route('stock-opname.show', $session)]);
    }

    /**
     * Fire-and-forget: deletes every tutorial-practice session this user has,
     * regardless of status. Called when the tutorial ends (skip, Escape, last
     * step) so an abandoned run doesn't leave an "In Progress" practice session
     * sitting around — harmless (already excluded from index()/store()) but
     * untidy left forever.
     */
    public function tutorialCleanup(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        StockOpname::where('started_by_user_id', $user->id)
            ->where('is_tutorial_practice', true)
            ->get()
            ->each->delete();

        return response()->json(['ok' => true]);
    }

    public function show(Request $request, StockOpname $stockOpname): View
    {
        $user = $request->user();
        abort_unless($user->mayParticipateInStockOpname(), 403);

        // Outfit's tutorial_scope global scope hides is_tutorial=true rows from
        // every normal query (see ExcludesTutorialData) — has to be lifted
        // explicitly here, or this always comes back empty for a practice
        // session, since that scope's own filter (is_tutorial=false) directly
        // contradicts the ->where('is_tutorial', true) below.
        // Eager-loaded so the table can flag "Currently Rented" — an outfit with an
        // active rental right now is legitimately off the shelf, not missing,
        // and staff shouldn't mark it Missing just because it's not physically
        // there to find.
        $withActiveRentals = fn ($query) => $query->where('status', 'active');

        $outfits = $stockOpname->is_tutorial_practice
            ? Outfit::withoutGlobalScope('tutorial_scope')->where('store_id', $stockOpname->store_id)->where('is_tutorial', true)
                ->with(['rentals' => $withActiveRentals])
                ->orderBy('code')->get(['id', 'code', 'new_code', 'description', 'price'])
            : Outfit::where('store_id', $stockOpname->store_id)
                ->with(['rentals' => $withActiveRentals])
                ->orderBy('code')->get(['id', 'code', 'new_code', 'description', 'price']);

        $itemsByOutfitId = $stockOpname->items()->get()->keyBy('outfit_id');
        $uncheckedCount = $outfits->count() - $itemsByOutfitId->count();

        // A finished session shows the outfits it actually covered and the numbers
        // it closed with — outfits added or deleted afterwards must not rewrite it.
        $snapshot = $stockOpname->isCompleted() ? $stockOpname->snapshot : null;
        if (is_array($snapshot) && isset($snapshot['outfit_ids'])) {
            $outfits = $outfits->whereIn('id', $snapshot['outfit_ids'])->values();
            $uncheckedCount = (int) ($snapshot['unchecked'] ?? $uncheckedCount);
        }

        // A tutorial-practice session is the user's own private sandbox, so
        // whoever is participating (not just Supervisor+) gets to complete
        // it too — otherwise an Admin walking the tutorial alone would never
        // see the "Finish Stock Opname" step of the real flow at all.
        $mayManage = $user->mayManageStockOpname()
            || ($stockOpname->is_tutorial_practice && $user->mayParticipateInStockOpname());

        return view('stock-opname.show', [
            'session' => $stockOpname,
            'outfits' => $outfits,
            'itemsByOutfitId' => $itemsByOutfitId,
            'uncheckedCount' => $uncheckedCount,
            'mayManage' => $mayManage,
        ]);
    }

    public function mark(Request $request, StockOpname $stockOpname): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless($user->mayParticipateInStockOpname(), 403);
        abort_if($stockOpname->isCompleted(), 422, 'This stock opname session is already finished and can no longer be changed.');

        $validated = $request->validate([
            // Only an outfit of THIS session's store, and of the matching kind (practice
            // sessions use tutorial outfits, real ones never do) — a raw exists:outfits,id
            // let any outfit id into any session and skewed its counts.
            'outfit_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('outfits', 'id')
                ->where(fn ($q) => $q->where('store_id', $stockOpname->store_id)->where('is_tutorial', (bool) $stockOpname->is_tutorial_practice))],
            'status' => ['required', 'in:ok,missing,damaged'],
            'note' => ['nullable', 'string', 'max:500'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        $existing = StockOpnameItem::where('stock_opname_id', $stockOpname->id)
            ->where('outfit_id', $validated['outfit_id'])
            ->first();

        // A photo only makes sense as evidence for Missing/Damaged — re-marking
        // an outfit "Present" (found after all) drops whatever photo was attached
        // to the earlier exception, same as its note gets cleared below.
        // The OLD file is only deleted after the row is saved: deleting first
        // and then failing to write left a row pointing at a missing file.
        $oldPhotoPath = $existing->photo_path ?? null;
        $photoPath = $oldPhotoPath;
        if ($validated['status'] === 'ok') {
            $photoPath = null;
        } elseif ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store("stock-opname/{$stockOpname->id}", 'local');
        }

        // A photo is required as evidence for "Damaged" — but only if this item
        // has never had a photo at all (re-editing the note does not need
        // another upload). "Missing" is deliberately not required — the item
        // is gone, so there is nothing to photograph. Checked server-side too,
        // not just in the client dialog, so it cannot be bypassed by posting
        // directly to this endpoint.
        if ($validated['status'] === 'damaged' && $photoPath === null) {
            return response()->json(['ok' => false, 'error' => 'A photo is required to mark an item as Damaged.'], 422);
        }

        // "Present" is recorded explicitly too, not just missing/damaged — an outfit
        // with no row here means genuinely unchecked ("Not Checked"), a real,
        // distinct state from "confirmed present" so staff can't skip through
        // the count on autopilot.
        try {
            $item = StockOpnameItem::upsertSafely(
                ['stock_opname_id' => $stockOpname->id, 'outfit_id' => $validated['outfit_id']],
                [
                    'status' => $validated['status'],
                    'note' => $validated['status'] === 'ok' ? null : ($validated['note'] ?? null),
                    'photo_path' => $photoPath,
                    'marked_by_user_id' => $user->id,
                ]
            );
        } catch (\Throwable $e) {
            // The new file never got a row — do not leave it orphaned.
            if ($photoPath && $photoPath !== $oldPhotoPath && Storage::disk('local')->exists($photoPath)) {
                Storage::disk('local')->delete($photoPath);
            }
            throw $e;
        }

        if ($oldPhotoPath && $oldPhotoPath !== $photoPath && Storage::disk('local')->exists($oldPhotoPath)) {
            Storage::disk('local')->delete($oldPhotoPath);
        }

        return response()->json([
            'ok' => true,
            'status' => $validated['status'],
            'item_id' => $item->id,
            'has_photo' => $photoPath !== null,
        ]);
    }

    /**
     * Streams a Missing/Damaged item's attached photo — served from the 'local'
     * (private) disk behind an auth check rather than the 'public' disk,
     * so it does not depend on `storage:link` (see InvoiceController's
     * pdf/png downloads for the same pattern).
     */
    public function itemPhoto(Request $request, StockOpnameItem $stockOpnameItem)
    {
        abort_unless($request->user()->mayParticipateInStockOpname(), 403);
        // StockOpname is store-scoped, StockOpnameItem is not: resolving the parent
        // session is what keeps another store's photos out of reach.
        abort_unless($stockOpnameItem->stockOpname()->exists(), 404);
        abort_unless($stockOpnameItem->photo_path && Storage::disk('local')->exists($stockOpnameItem->photo_path), 404);

        return Storage::disk('local')->response($stockOpnameItem->photo_path);
    }

    /**
     * Called by the in-page camera scanner (resources/js/app.js's QrScanner)
     * once it decodes an outfit's printed QR — the QR encodes an absolute
     * outfits.scan URL (see OutfitController::scanByToken), so the client
     * extracts just the token and posts that here. A successful scan is
     * itself physical proof the item was found, so it auto-marks "ok"
     * (Present) directly — no separate manual tap needed, same as clicking
     * "Present" by hand, including overwriting a prior Missing/Damaged mark if the
     * item turns out to actually be there after all.
     */
    public function scan(Request $request, StockOpname $stockOpname): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless($user->mayParticipateInStockOpname(), 403);
        abort_if($stockOpname->isCompleted(), 422, 'This stock opname session is already finished and can no longer be changed.');

        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $outfitsQuery = $stockOpname->is_tutorial_practice
            ? Outfit::withoutGlobalScope('tutorial_scope')->where('is_tutorial', true)
            : Outfit::query();

        // Scoped to this session's own store — a QR for a Demo Store Two item
        // scanned during a session in another store shouldn't silently mark anything.
        $outfit = $outfitsQuery->where('store_id', $stockOpname->store_id)
            ->where('qr_token', $validated['token'])
            ->first(['id', 'code', 'description']);

        if (! $outfit) {
            return response()->json(['ok' => false, 'error' => 'QR code not recognised, or the item does not belong to this store.'], 404);
        }

        // Scanning an outfit found again clears its exception, so the old photo goes too
        // (mark() does the same for an explicit "Present").
        $previous = StockOpnameItem::where('stock_opname_id', $stockOpname->id)->where('outfit_id', $outfit->id)->first();
        StockOpnameItem::upsertSafely(
            ['stock_opname_id' => $stockOpname->id, 'outfit_id' => $outfit->id],
            ['status' => 'ok', 'note' => null, 'photo_path' => null, 'marked_by_user_id' => $user->id]
        );
        if ($previous && $previous->photo_path && Storage::disk('local')->exists($previous->photo_path)) {
            Storage::disk('local')->delete($previous->photo_path);
        }

        return response()->json([
            'ok' => true,
            'outfit_id' => $outfit->id,
            'code' => $outfit->code,
            'description' => $outfit->description,
        ]);
    }

    /**
     * Read-only rental info for the "Currently Rented" badge on the opname
     * table — deliberately its own tiny endpoint rather than reusing
     * RentalController::detailFragment(), whose rendered partials include a
     * "Select rent" button wired to the Items page's own rental-creation
     * cart (selectRentalIntoCart()), which doesn't exist on this page and
     * makes no sense in an opname context anyway.
     */
    public function rentalDetail(Request $request, Outfit $outfit): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()->mayParticipateInStockOpname(), 403);

        $rentals = $outfit->rentals()
            ->where('status', 'active')
            ->orderBy('start_date')
            ->get(['rentals.id', 'customer_name', 'phone', 'start_date', 'end_date']);

        return response()->json([
            'ok' => true,
            'code' => $outfit->code,
            'rentals' => $rentals->map(fn ($r) => [
                'customer_name' => $r->customer_name,
                'phone' => $r->phone,
                'start_label' => $r->start_date->format('d M Y'),
                'end_label' => $r->end_date->format('d M Y'),
            ]),
        ]);
    }

    /**
     * Super Admin only — fills in every still-"Not Checked" item at once
     * with the same status, so a session with hundreds of items doesn't
     * require tapping through each one by hand to close out. Deliberately
     * never touches an item someone already marked (Present/Missing/Damaged) —
     * see the confirm dialog this posts from, which states the count of
     * items this will actually change before submitting.
     */
    public function bulkMark(Request $request, StockOpname $stockOpname): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin(), 403);
        abort_if($stockOpname->isCompleted(), 422, 'This stock opname session is already finished and can no longer be changed.');

        // "Damaged" is not offered in bulk: it needs a photo per item, which a
        // one-click fill-in cannot provide.
        $validated = $request->validate([
            'status' => ['required', 'in:ok,missing'],
        ]);

        $outfitIds = $stockOpname->is_tutorial_practice
            ? Outfit::withoutGlobalScope('tutorial_scope')->where('store_id', $stockOpname->store_id)->where('is_tutorial', true)->pluck('id')
            : Outfit::where('store_id', $stockOpname->store_id)->pluck('id');

        $alreadyMarkedOutfitIds = $stockOpname->items()->pluck('outfit_id');
        // An outfit that is out with a customer right now is not missing — a one-click
        // "Missing" (or "Present") must leave it for staff to judge by hand.
        $outOnRentOutfitIds = DB::table('rental_outfit')
            ->join('rentals', 'rentals.id', '=', 'rental_outfit.rental_id')
            ->whereIn('rental_outfit.outfit_id', $outfitIds)
            ->where('rentals.status', 'active')
            ->whereNotNull('rentals.picked_up_at')
            ->whereNull('rentals.deleted_at')
            ->pluck('rental_outfit.outfit_id');
        $uncheckedOutfitIds = $outfitIds->diff($alreadyMarkedOutfitIds)->diff($outOnRentOutfitIds);

        $now = now();
        $rows = $uncheckedOutfitIds->map(fn ($outfitId) => [
            'stock_opname_id' => $stockOpname->id,
            'outfit_id' => $outfitId,
            'status' => $validated['status'],
            'note' => null,
            'marked_by_user_id' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        // Chunked: one huge insert can exceed the database's placeholder limit.
        foreach (array_chunk($rows, 500) as $chunk) {
            StockOpnameItem::insert($chunk);
        }

        $labels = ['ok' => 'Present', 'missing' => 'Missing', 'damaged' => 'Damaged'];

        return redirect()->route('stock-opname.show', $stockOpname)
            ->with('success', count($rows).' unchecked item(s) were marked '.$labels[$validated['status']].' in one go.'
                .($outOnRentOutfitIds->isNotEmpty() ? ' '.$outOnRentOutfitIds->unique()->count().' item(s) currently out with a customer were skipped — check them manually.' : ''));
    }

    /**
     * An outfit's full opname trail across every session it's ever been part
     * of — the per-item "log" that complements the session-level history
     * already on stock-opname.index. Same participant-level access as the
     * rest of Stock Opname (Admin included), not narrowed to Supervisor+.
     */
    public function itemHistory(Request $request, Outfit $outfit): View
    {
        abort_unless($request->user()->mayParticipateInStockOpname(), 403);

        $items = StockOpnameItem::where('outfit_id', $outfit->id)
            ->with(['markedBy', 'stockOpname'])
            ->whereHas('stockOpname', function ($query) {
                $query->where('is_tutorial_practice', false);
            })
            ->latest('updated_at')
            ->get();

        return view('stock-opname.item-history', [
            'outfit' => $outfit,
            'items' => $items,
        ]);
    }

    public function complete(Request $request, StockOpname $stockOpname): RedirectResponse
    {
        $user = $request->user();
        abort_unless(
            $user->mayManageStockOpname() || ($stockOpname->is_tutorial_practice && $user->mayParticipateInStockOpname()),
            403
        );
        abort_if($stockOpname->isCompleted(), 422, 'This session is already finished.');

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($stockOpname, $user, $validated) {
            // Re-read under a lock: a double click passes the check above twice,
            // and the second pass must not overwrite who finished it and when.
            $locked = StockOpname::withoutGlobalScope('store_scope')->whereKey($stockOpname->id)->lockForUpdate()->first();
            if (! $locked || $locked->isCompleted()) {
                return;
            }

            $locked->update([
                'completed_at' => now(),
                'completed_by_user_id' => $user->id,
                'notes' => $validated['notes'] ?? null,
                'snapshot' => $this->buildSnapshot($locked),
            ]);
        });

        return redirect()->route('stock-opname.show', $stockOpname)->with('success', 'Stock opname recorded as finished.');
    }

    /**
     * PDF export of a finished session — only meaningful once completed
     * (an in-progress session's exceptions can still change), mirroring
     * ShiftController's Pdf::loadView/Dompdf fallback pattern.
     */
    public function downloadPdf(Request $request, StockOpname $stockOpname): Response
    {
        abort_unless($request->user()->mayParticipateInStockOpname(), 403);
        abort_unless($stockOpname->isCompleted(), 422, 'This session is not finished yet — the PDF can only be downloaded after the stock opname is completed.');

        $stockOpname->loadMissing(['startedBy', 'completedBy', 'store']);
        $exceptions = $stockOpname->items()
            ->whereIn('status', ['missing', 'damaged'])
            ->with(['outfit', 'markedBy'])
            ->get();

        // A practice session only ever covers the store's 2 is_tutorial Outfits,
        // not its real inventory (see show()'s identical branch) — counting
        // every real Outfit here would make a practice PDF's "Not Checked"
        // wildly wrong (hundreds, instead of the 0-2 that's actually true).
        $snapshot = $stockOpname->snapshot;
        if (is_array($snapshot) && isset($snapshot['total'])) {
            // The numbers the session closed with — not recomputed from today's outfit list.
            $totalItems = (int) $snapshot['total'];
            $okCount = (int) $snapshot['ok'];
            $missingCount = (int) $snapshot['missing'];
            $damagedCount = (int) $snapshot['damaged'];
            $uncheckedCount = (int) $snapshot['unchecked'];
        } else {
            $counts = $this->liveCounts($stockOpname);
            [$totalItems, $okCount, $missingCount, $damagedCount, $uncheckedCount] = [$counts['total'], $counts['ok'], $counts['missing'], $counts['damaged'], $counts['unchecked']];
        }

        $data = [
            'stockOpname' => $stockOpname,
            'exceptions' => $exceptions,
            'totalItems' => $totalItems,
            'okCount' => $okCount,
            'missingCount' => $missingCount,
            'damagedCount' => $damagedCount,
            'uncheckedCount' => $uncheckedCount,
        ];

        if (class_exists(Pdf::class)) {
            $binary = Pdf::loadView('stock-opname.report-pdf', $data)->output();
        } elseif (class_exists(Dompdf::class)) {
            $dompdf = new Dompdf;
            $dompdf->loadHtml(view('stock-opname.report-pdf', $data)->render());
            $dompdf->render();
            $binary = $dompdf->output();
        } else {
            abort(500, 'Dompdf is not installed.');
        }

        $name = 'stock-opname-'.Str::slug($stockOpname->store->name ?? 'store').'-'.$stockOpname->started_at->format('Y-m-d').'.pdf';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
    /**
     * Counts from the outfit list and items as they are RIGHT NOW. Used to build
     * the snapshot at completion, and as the fallback for sessions completed
     * before snapshots existed.
     *
     * @return array{total: int, ok: int, missing: int, damaged: int, unchecked: int, outfit_ids: list<int>}
     */
    private function liveCounts(StockOpname $stockOpname): array
    {
        $outfitIds = ($stockOpname->is_tutorial_practice
            ? Outfit::withoutGlobalScope('tutorial_scope')->where('store_id', $stockOpname->store_id)->where('is_tutorial', true)
            : Outfit::withoutGlobalScope('store_scope')->where('store_id', $stockOpname->store_id))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $byStatus = $stockOpname->items()->whereIn('outfit_id', $outfitIds)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $checked = (int) $byStatus->sum();

        return [
            'total' => count($outfitIds),
            'ok' => (int) ($byStatus['ok'] ?? 0),
            'missing' => (int) ($byStatus['missing'] ?? 0),
            'damaged' => (int) ($byStatus['damaged'] ?? 0),
            'unchecked' => count($outfitIds) - $checked,
            'outfit_ids' => $outfitIds,
        ];
    }

    private function buildSnapshot(StockOpname $stockOpname): array
    {
        return $this->liveCounts($stockOpname) + ['taken_at' => now()->toIso8601String()];
    }
}

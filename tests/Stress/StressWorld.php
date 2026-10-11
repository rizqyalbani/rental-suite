<?php

namespace Tests\Stress;

use App\Models\Account;
use App\Models\Outfit;
use App\Models\User;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A small shop world (2 stores, every role, outfits, open shifts) plus every money action the
 * app offers, driven only through the REAL HTTP routes (no model shortcuts).
 *
 * Each `do_*` action picks its own target and parameters from the seeded Rng and returns false
 * when it has nothing to act on. Actions that the app must refuse (wrong role, wrong store,
 * locked rent, replayed request) are executed too, flagged `$forbidden`: the world snapshots
 * every money table before and after and records a violation if anything changed.
 *
 * ---- Actions deliberately EXCLUDED because the bug is already known (overnight 2026-10-09) ----
 *  P1   overdue "change" counted as rent paid        -> overdue_* fields are never sent
 *  P2   every Save re-prices items at catalogue price -> outfit catalogue prices are never changed
 *  P3   edit / Pay accepted on a cancelled rent       -> cancelled rents are never edited or paid
 *  P4/P4b/C2-6/C6-02 practice / real id collision     -> only "practice create" runs; no practice edit/cancel/delete
 *  P6   outfit of another store attached at Rp 0       -> outfits are always taken from the acting store
 *  C2-1 cancel-with-refund while item removal pending -> refund-cancel skipped in that state; item removal on cancelled skipped
 *  C3-6 cancel-with-refund after an earlier refund    -> refund-cancel skipped when the rent already had a refund
 *  C2-2 older edit snapshot un-frees a free rent      -> no edit request while a free request is pending
 *  C2-3 free request with extra_charge                -> free requests are never filed on a rent that has an additional charge
 *  C2-4 supervisor of store 2 decides store 1 request -> deciders are always of the request's store
 *  C2-10 late "return" skips the Return gates         -> late "return" is only reported for paid, picked-up rents
 *  C3-3 down payment covering the whole bill          -> a down payment is always below the bill
 *  C6-03 tutorial_practice=1 skips the shift rule     -> practice create is run by roles that never need a shift
 */
final class StressWorld
{
    /** @var array<string, User> */
    public array $users = [];

    /** @var array<string, array{role: string, home: ?int}> */
    public array $meta = [];

    /** @var array<int, list<int>> store id => outfit ids */
    public array $outfits = [];

    /** @var list<string> */
    public array $trace = [];

    /** @var list<array{id: string, msg: string, step: int, trace_len: int}> */
    public array $violations = [];

    /** @var array<string, int> known-finding exclusions that actually removed a candidate */
    public array $excluded = [];

    /** @var array<string, int> how many times each action ran */
    public array $ran = [];

    /** @var array<string, int> status code histogram per action */
    public array $statuses = [];

    public int $step = 0;

    public Carbon $startedAt;

    private const OPERATORS = ['admin1', 'admin2', 'admin3', 'sup1', 'sup1b', 'sup2', 'mgr1', 'mgr1b', 'mgr2', 'sa'];

    private const DECIDERS = ['sup1', 'sup1b', 'sup2', 'mgr1', 'mgr1b', 'mgr2', 'sa'];

    private const MANAGERS = ['mgr1', 'mgr1b', 'mgr2', 'sa'];

    private const PRIVILEGED = ['management', 'super_admin', 'accounting'];

    public function __construct(private readonly TestCase $t, private readonly Rng $rng)
    {
    }

    // =====================================================================================
    // Setup
    // =====================================================================================

    public function build(): void
    {
        foreach ([
            ['1100', 'Store Cash', 'asset', 'debit', true], ['1200', 'Bank', 'asset', 'debit', true],
            ['1300', 'Rental Receivable', 'asset', 'debit', false], ['2300', 'Customer Deposits', 'liability', 'credit', false],
            ['4100', 'Rental Revenue', 'revenue', 'credit', false], ['4200', 'Late Fee Revenue', 'revenue', 'credit', false],
            ['4400', 'Forfeited Deposits', 'revenue', 'credit', false], ['4910', 'Rental Refunds', 'revenue', 'debit', false],
        ] as [$code, $name, $type, $normal, $cash]) {
            Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_cash_equivalent' => $cash, 'is_active' => true]);
        }

        foreach ([1, 2] as $store) {
            StoreContext::set($store);
            StoreCache::forever('pricing_tax_percent', 10);
            StoreCache::forever('pricing_prices_include_tax', true);
        }
        StoreContext::set(null, false);

        $roster = [
            // key => [role, home store (null = sees every store)]
            'admin1' => ['admin', 1], 'admin2' => ['admin', 1], 'admin3' => ['admin', 2],
            'sup1' => ['supervisor', 1], 'sup1b' => ['supervisor', 1], 'sup2' => ['supervisor', 2],
            'mgr1' => ['management', 1], 'mgr1b' => ['management', 1], 'mgr2' => ['management', 2],
            'sa' => ['super_admin', 1], 'acc' => ['accounting', 1],
        ];
        foreach ($roster as $key => [$role, $home]) {
            $user = User::factory()->create(['role' => $role, 'name' => $key]);
            $user->stores()->attach($home);
            if (in_array($role, ['management', 'super_admin', 'accounting'], true)) {
                $user->stores()->syncWithoutDetaching([1, 2]);
            }
            $this->users[$key] = $user;
            $this->meta[$key] = ['role' => $role, 'home' => $home];
        }

        $prices = [1 => [330000, 440000, 550000, 660000, 770000, 880000, 1100000, 275000], 2 => [330000, 550000, 1100000, 440000]];
        foreach ($prices as $store => $list) {
            foreach ($list as $i => $price) {
                $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $store, 'code' => "S{$store}-".($i + 1), 'price' => $price, 'stock' => 1]);
                $this->outfits[$store][] = (int) $outfit->id;
            }
        }

        $this->startedAt = Carbon::create(2026, 9, 28, 10, 0, 0);
        Carbon::setTestNow($this->startedAt->copy());

        foreach (['admin1', 'admin2', 'admin3'] as $adminKey) {
            $this->http('post', route('shifts.open'), ['period' => 'morning'], $adminKey, $this->meta[$adminKey]['home']);
        }
    }

    public function today(): string
    {
        return Carbon::now()->toDateString();
    }

    /**
     * Calibration switch: STRESS_INCLUDE_KNOWN=P3,P2,C2-2,C3-6 puts an excluded action back in, to prove the
     * walk re-finds that known problem (see the report, "Does the walk find bugs at all?").
     */
    private function known(string $id): bool
    {
        return in_array($id, array_map('trim', explode(',', (string) getenv('STRESS_INCLUDE_KNOWN'))), true);
    }

    // =====================================================================================
    // The random step
    // =====================================================================================

    public function randomStep(): void
    {
        $weights = [
            'create' => 14, 'pay' => 12, 'edit' => 12, 'approve' => 13, 'reject' => 4, 'cancel' => 5, 'reopen' => 2,
            'pickup' => 4, 'return' => 3, 'flow' => 7, 'free' => 3, 'late_done' => 2, 'delete' => 3, 'assign' => 5,
            'shift' => 3, 'practice' => 2, 'approval_cancel' => 2, 'advance' => 5,
        ];
        if ($this->known('P2')) {
            $weights['reprice'] = 4;
        }
        for ($try = 0; $try < 10; $try++) {
            $name = $this->rng->weighted($weights);
            $this->ran[$name] = ($this->ran[$name] ?? 0);
            try {
                if ($this->{'do_'.$name}()) {
                    $this->ran[$name]++;

                    return;
                }
            } catch (SkipAction $skip) {
                $this->excluded[$skip->getMessage()] = ($this->excluded[$skip->getMessage()] ?? 0) + 1;
            }
        }
        $this->do_advance();
    }

    // =====================================================================================
    // Actions
    // =====================================================================================

    /** Only with STRESS_INCLUDE_KNOWN=P2: the catalogue price of an outfit changes (P2: every later Save re-prices the rent). */
    private function do_reprice(): bool
    {
        $store = $this->rng->pick([1, 2]);
        $id = $this->rng->pick($this->outfits[$store]);
        DB::table('outfits')->where('id', $id)->update(['price' => $this->rng->pick([330000, 440000, 550000, 660000, 990000])]);
        $this->step++;
        $this->trace[] = sprintf('#%d [catalogue] outfit#%d price changed (P2 calibration)', $this->step, $id);

        return true;
    }

    private function do_advance(): bool
    {
        $before = Carbon::now()->toDateString();
        $to = $this->rng->chance(45) ? Carbon::now()->addDay()->setTime(9, 30) : Carbon::now()->addHours($this->rng->int(1, 5));
        Carbon::setTestNow($to);
        $this->step++;
        $this->trace[] = sprintf('#%d [clock] now=%s (was day %s)', $this->step, $to->toDateTimeString(), $before);

        return true;
    }

    private function do_create(): bool
    {
        $actor = $this->actor(self::OPERATORS, 10);
        $store = $this->effectiveStore($actor, $this->rng->pick([1, 1, 2]));
        $session = $this->sessionStore($actor, $store);

        $days = $this->rng->pick([3, 3, 6, 9, 3, 6, 4, 5, 7, 8]);
        $extra = $days % 3 === 0 ? 0 : $this->rng->pick([20000, 35000, 60000]); // not a multiple of 3 days -> an additional charge is required
        $start = Carbon::now()->startOfDay()->addDays($this->rng->int(-1, 4));
        $end = $start->copy()->addDays($days - 1);
        $count = $this->rng->pick([1, 1, 2, 3]);
        $outfitIds = null;
        if ($this->rng->chance(12)) {
            // Cool-down boundary: start the day after another active rent of the same outfit ends
            // (admin -> 'create' approval request, management -> created directly).
            $neighbours = DB::table('rentals')->whereNull('deleted_at')->where('status', 'active')->where('store_id', $store)->get();
            if ($neighbours->isNotEmpty()) {
                $neighbour = $this->rng->pick($neighbours->all());
                $neighbourOutfits = DB::table('rental_outfit')->where('rental_id', $neighbour->id)->pluck('outfit_id')->map(fn ($v) => (int) $v)->all();
                if ($neighbourOutfits !== []) {
                    $outfitIds = [$this->rng->pick($neighbourOutfits)];
                    $start = Carbon::parse(substr((string) $neighbour->end_date, 0, 10))->addDay();
                    $end = $start->copy()->addDays($days - 1);
                }
            }
        }
        $outfitIds ??= $this->pickFreeOutfits($store, $start, $end, $count, $this->rng->chance(85));
        if ($outfitIds === []) {
            return false;
        }

        $payload = [
            'outfit_ids' => $outfitIds, 'customer_name' => 'Cust '.$this->rng->int(1, 999), 'phone' => '08'.$this->rng->int(10000000, 99999999),
            'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
            'payment_method' => $this->rng->pick(['cash', 'cash', 'qris', 'transfer']),
        ];
        $discount = $this->rng->weighted(['none' => 70, 'fixed' => 14, 'percent' => 12, 'item' => 10]);
        $itemDiscounts = [];
        $itemTypes = [];
        $itemNotes = [];
        if ($discount === 'fixed') {
            $payload += ['discount' => (string) $this->rng->pick([10000, 25000, 50000]), 'discount_type' => 'fixed', 'discount_note' => 'promo'];
        } elseif ($discount === 'percent') {
            $payload += ['discount' => (string) $this->rng->pick([10, 20]), 'discount_type' => 'percent', 'discount_note' => 'promo'];
        } elseif ($discount === 'item') {
            $sid = $outfitIds[0];
            $itemDiscounts[$sid] = (string) $this->rng->pick([20000, 30000]);
            $itemTypes[$sid] = 'fixed';
            $itemNotes[$sid] = 'item promo';
        }
        if ($itemDiscounts !== []) {
            $payload += ['item_discounts' => $itemDiscounts, 'item_discount_types' => $itemTypes, 'item_discount_notes' => $itemNotes];
        }

        if ($extra > 0) {
            $payload['extra_charge'] = (string) $extra;
        }
        $total = $this->plannedBill($store, $outfitIds, $days, $payload['discount'] ?? null, $payload['discount_type'] ?? 'fixed', $itemDiscounts) + $extra;
        if ($total < 5000) {
            return false;
        }
        if ($this->rng->chance(40)) {
            $payload += ['payment_kind' => 'full', 'amount_paid' => (string) $total];
            $paid = $total;
        } else {
            // C3-3 (down payment that already covers the whole bill) is excluded: DP stays below the bill.
            $dp = floor($total * $this->rng->pick([20, 30, 50, 70]) / 100 / 1000) * 1000;
            $dp = max(1000, min($dp, $total - 1000));
            $payload += ['payment_kind' => 'down_payment', 'amount_paid' => (string) $dp];
            $paid = $dp;
        }

        $role = $this->meta[$actor]['role'];
        $forbidden = $role === 'accounting' || ($role === 'admin' && ! $this->hasOpenShift($actor));
        $why = $role === 'accounting' ? 'accounting cannot create rents' : 'admin without an open shift';
        $label = sprintf('create store%d outfits=%s %dd paid=%s/%s %s', $store, implode(',', $outfitIds), $days, $paid, $total, $payload['payment_kind']);
        $this->exec($label, $actor, $forbidden, $why, fn () => $this->http('post', route('rentals.store'), $payload, $actor, $session));

        return true;
    }

    private function do_pay(): bool
    {
        $r = $this->pickRental(fn ($x) => $x->status === 'active' || ($this->known('P3') && $x->status === 'cancelled'), 'P3', fn ($x) => $x->status === 'cancelled');
        if (! $r) {
            return false;
        }
        if (! $this->known('C2-2') && $this->pendingOf((int) $r->id, ['free'])) {
            // C2-2: an admin Pay/Save that files an edit request while a free request waits is later applied over the refunded rent.
            $this->excluded['C2-2 (pay/edit while a free request is pending)'] = ($this->excluded['C2-2 (pay/edit while a free request is pending)'] ?? 0) + 1;

            return false;
        }
        $actor = $this->operatorFor((int) $r->store_id, 12);
        $this->payRental($r, $actor, $this->rng->weighted(['partial' => 30, 'exact' => 45, 'over' => 8, 'zero' => 4, 'settled' => 8]));

        return true;
    }

    /** One Pay-button request (the edit modal with only the payment fields moving). */
    private function payRental(object $r, string $actor, string $mode): void
    {
        $remaining = round($this->billOf($r) - $this->paidOf((int) $r->id), 2);
        if ($remaining <= 0.004) {
            $mode = 'settled';
        } elseif ($mode === 'settled') {
            $mode = 'partial';
        }
        $amount = match ($mode) {
            'partial' => max(1000, floor($remaining * $this->rng->pick([30, 50, 70]) / 100 / 1000) * 1000),
            'exact' => $remaining,
            'over' => $remaining + 1000,
            'zero' => 0,
            default => 1000,
        };
        if ($mode === 'partial' && $amount >= $remaining) {
            $mode = 'exact';
            $amount = $remaining;
        }
        $complete = $mode === 'exact';
        $payload = $this->basePayload($r);
        $payload['payment_amount_add'] = (string) $amount;
        $payload['pay_action'] = '1';
        $payload['payment_method'] = $this->rng->pick(['cash', 'qris', 'transfer']);
        $payload['payment_kind'] = $complete ? ($r->payment_kind === 'down_payment' ? 'final_payment' : 'full') : $r->payment_kind;

        [$forbidden, $why, $session] = $this->rentalCtx($actor, $r, true);
        $moneyOnly = false;
        if (! $forbidden && in_array($mode, ['over', 'zero', 'settled'], true)) {
            // Payment beyond the bill / nothing to pay: no money may move (a metadata touch or a waiting request is tolerated here).
            [$forbidden, $why, $moneyOnly] = [true, "pay mode {$mode}: no money may move", true];
        }
        if (! $forbidden && $this->pendingOf((int) $r->id, ['edit'])) {
            [$forbidden, $why] = [true, 'payment is blocked while an edit request is waiting'];
        }
        $label = sprintf('pay rental#%d %s %s (remaining %s, kind %s)', $r->id, $mode, $amount, $remaining, $r->payment_kind);
        $this->exec($label, $actor, $forbidden, $why, fn () => $this->http('put', route('rentals.update', $r->id), $payload, $actor, $session), moneyOnly: $moneyOnly);
    }

    /** The happy path in one go: collect the balance, hand the outfit over, take it back. */
    private function do_flow(): bool
    {
        $r = $this->pickRental(fn ($x) => $x->status === 'active' && ! $this->pendingOf((int) $x->id, ['edit', 'free', 'delete']), null, null);
        if (! $r) {
            return false;
        }
        $store = (int) $r->store_id;
        $actor = $this->operatorFor($store, 0);
        $role = $this->meta[$actor]['role'];
        if ($role === 'accounting' || ($role === 'admin' && ! $this->hasOpenShift($actor))) {
            return false;
        }
        $session = $store;
        $remaining = round($this->billOf($r) - $this->paidOf((int) $r->id), 2);
        if ($remaining > 0.004) {
            $this->payRental($r, $actor, 'exact');
            $r = DB::table('rentals')->where('id', $r->id)->first();
        }
        $this->exec(sprintf('flow-pickup rental#%d', $r->id), $actor, false, '', fn () => $this->http('post', route('rentals.pickup', $r->id), ['picked_up' => true], $actor, $session));
        if ($this->rng->chance(70)) {
            $this->exec(sprintf('flow-return rental#%d', $r->id), $actor, false, '', fn () => $this->http('post', route('rentals.status', $r->id), ['status' => 'returned'], $actor, $session));
        }

        return true;
    }

    private function do_edit(): bool
    {
        $allowReturned = $this->rng->chance(12); // returned rents are locked: keep a few to prove the lock, not all of them
        $r = $this->pickRental(
            fn ($x) => ($x->status === 'active' || ($allowReturned && $x->status === 'returned') || ($this->known('P3') && $x->status === 'cancelled')) && ($this->known('C2-2') || ! $this->pendingOf((int) $x->id, ['free'])),
            'P3/C2-2',
            fn ($x) => $x->status === 'cancelled' || $this->pendingOf((int) $x->id, ['free']) !== null,
        );
        if (! $r) {
            return false;
        }
        $actor = $this->operatorFor((int) $r->store_id, 12);
        $store = (int) $r->store_id;
        $payload = $this->basePayload($r);
        $pivots = DB::table('rental_outfit')->where('rental_id', $r->id)->get();
        $calDays = $this->calDays($r);
        $refunded = $this->cashBack((int) $r->id) > 0.004;
        $kind = $this->rng->weighted(['meta' => 2, 'dates' => 3, 'add_item' => 2, 'remove_item' => 5, 'order_discount' => 4, 'item_discount' => 2, 'method' => 1, 'replace_amount' => 4]);
        if ($refunded && ! in_array($kind, ['meta', 'method', 'remove_item', 'replace_amount'], true) && ! $this->known('N3')) {
            // N3/N3b (new): after any refund, an edit that can raise the bill again is absorbed by amount_paid (which still counts the refunded money).
            $this->excluded['N3 (bill-raising edit on a rent that already had money handed back)'] = ($this->excluded['N3 (bill-raising edit on a rent that already had money handed back)'] ?? 0) + 1;
            $kind = 'meta';
        }
        switch ($kind) {
            case 'meta':
                $payload['customer_name'] = 'Edited '.$this->rng->int(1, 99);
                $payload['notes'] = 'note '.$this->rng->int(1, 9);
                break;
            case 'dates':
                $newDays = $this->rng->pick(array_values(array_diff([3, 6, 9, 4, 5, 7], [$calDays])));
                $payload['end_date'] = Carbon::parse(substr($r->start_date, 0, 10))->addDays($newDays - 1)->toDateString();
                $payload['extra_charge'] = $newDays % 3 === 0 ? '' : (string) $this->rng->pick([20000, 35000, 60000]);
                break;
            case 'add_item':
                $free = $this->pickFreeOutfits($store, Carbon::parse(substr($r->start_date, 0, 10)), Carbon::parse(substr($r->end_date, 0, 10)), 1, true, $payload['outfit_ids']);
                if ($free === []) {
                    return false;
                }
                $payload['outfit_ids'][] = $free[0];
                $payload['item_discounts'][$free[0]] = '';
                $payload['item_discount_types'][$free[0]] = 'fixed';
                break;
            case 'remove_item':
                if (count($payload['outfit_ids']) < 2) {
                    return false;
                }
                $drop = $this->rng->pick($payload['outfit_ids']);
                $payload['outfit_ids'] = array_values(array_diff($payload['outfit_ids'], [$drop]));
                unset($payload['item_discounts'][$drop], $payload['item_discount_types'][$drop], $payload['item_discount_notes'][$drop]);
                break;
            case 'order_discount':
                if ($this->rng->chance(35)) {
                    $payload['discount'] = '';
                    $payload['discount_note'] = null;
                } elseif ($this->rng->chance(50)) {
                    $payload['discount'] = (string) $this->rng->pick([10000, 25000, 50000]);
                    $payload['discount_type'] = 'fixed';
                    $payload['discount_note'] = 'promo edit';
                } else {
                    $payload['discount'] = (string) $this->rng->pick([10, 20]);
                    $payload['discount_type'] = 'percent';
                    $payload['discount_note'] = 'promo edit';
                }
                break;
            case 'item_discount':
                $sid = $this->rng->pick($payload['outfit_ids']);
                $payload['item_discounts'][$sid] = (string) $this->rng->pick([20000, 30000]);
                $payload['item_discount_types'][$sid] = 'fixed';
                $payload['item_discount_notes'][$sid] = 'item promo edit';
                break;
            case 'replace_amount':
                // The edit modal's own Save path: payment_entry=replace with the TOTAL received so far (same, lower = correction, higher = more money).
                $paid = $this->paidOf((int) $r->id);
                $bill = $this->billOf($r);
                $choice = $this->rng->pick(['same', 'lower', 'higher', 'lower']);
                $amt = match ($choice) {
                    'lower' => max(1000.0, $paid - $this->rng->pick([10000, 30000, 100000])),
                    'higher' => min($bill, $paid + $this->rng->pick([10000, 50000, 200000])),
                    default => $paid,
                };
                unset($payload['payment_amount_add']);
                $payload['payment_entry'] = 'replace';
                $payload['amount_paid'] = (string) $amt;
                if ($amt + 0.004 >= $bill) {
                    $payload['payment_kind'] = $r->payment_kind === 'down_payment' ? 'final_payment' : 'full';
                }
                $kind = "replace_amount({$choice} {$paid}->{$amt})";
                break;
            default:
                $payload['payment_method'] = $this->rng->pick(['cash', 'qris', 'transfer']);
        }

        [$forbidden, $why, $session] = $this->rentalCtx($actor, $r, true);
        if (! $forbidden && $r->status === 'returned') {
            [$forbidden, $why] = [true, 'a returned rent is locked for edit'];
        }
        if (! $forbidden && $this->meta[$actor]['role'] === 'admin' && $this->pendingOf((int) $r->id, ['edit'])) {
            [$forbidden, $why] = [true, 'admin already has an edit request waiting on this rent'];
        }
        $label = sprintf('edit rental#%d %s (paid %s, bill %s, %d items)', $r->id, $kind, $this->paidOf((int) $r->id), $this->billOf($r), $pivots->count());
        $res = $this->exec($label, $actor, $forbidden, $why, fn () => $this->http('put', route('rentals.update', $r->id), $payload, $actor, $session));
        $this->maybeReplay($res, $label, $actor, $this->meta[$actor]['role'] === 'admin' && ($res['json']['mode'] ?? null) === 'edit_approval_request_sent',
            fn () => $this->http('put', route('rentals.update', $r->id), $payload, $actor, $session));

        return true;
    }

    private function do_approve(): bool
    {
        return $this->decide('approve');
    }

    private function do_reject(): bool
    {
        return $this->decide('reject');
    }

    private function decide(string $decision): bool
    {
        $pending = DB::table('rental_approval_requests')->where('status', 'pending')->get()
            ->filter(fn ($a) => (($this->decode($a->payload)['kind'] ?? 'create') !== 'payment_assign'))
            ->values();
        if ($pending->isEmpty()) {
            return false;
        }
        $a = $this->rng->pick($pending->all());
        $p = $this->decode($a->payload);
        $kind = (string) ($p['kind'] ?? 'create');
        $rental = isset($p['rental_id']) ? DB::table('rentals')->where('id', $p['rental_id'])->first() : null;
        $approvalStore = (int) ($a->store_id ?: 1);

        if ($decision === 'approve' && $kind === 'edit' && $rental && $this->cashBack((int) $rental->id) > 0.004 && ! $this->known('N3')) {
            // N3/N3b: an edit request approved after money was handed back may raise the bill again.
            $this->excluded['N3 (approving an edit on a rent that already had money handed back)'] = ($this->excluded['N3 (approving an edit on a rent that already had money handed back)'] ?? 0) + 1;

            return false;
        }
        if ($kind === 'item_removal' && $rental && $rental->status === 'cancelled') {
            $this->excluded['C2-1 (item removal decided on a cancelled rent)'] = ($this->excluded['C2-1 (item removal decided on a cancelled rent)'] ?? 0) + 1;

            return false;
        }

        // C2-4 (a supervisor of the other store can decide) is excluded: only deciders of the request's own store.
        $natural = array_values(array_filter(self::DECIDERS, fn ($k) => $this->meta[$k]['home'] === $approvalStore || in_array($this->meta[$k]['role'], ['management', 'super_admin'], true)));
        $forbiddenPick = $this->rng->chance(18);
        $actor = $forbiddenPick
            ? $this->rng->pick(array_values(array_filter(array_keys($this->users), fn ($k) => $this->isPrivileged($k) || $this->meta[$k]['home'] === $approvalStore)))
            : $this->rng->pick($natural);
        // The requester is a natural candidate for the self-approval check.
        if (! $forbiddenPick && $this->rng->chance(15)) {
            $requesterKey = array_search((int) $a->requested_by_user_id, array_map(fn ($u) => (int) $u->id, $this->users), true);
            if ($requesterKey !== false && in_array($requesterKey, $natural, true)) {
                $actor = $requesterKey;
            }
        }

        $why = $this->decisionForbidden($actor, $a, $p, $decision);
        $route = $kind === 'item_removal'
            ? route('approvals.item-removal.'.$decision, $a->id)
            : route('approvals.rentals.'.$decision, $a->id);
        $session = $this->sessionStore($actor, $approvalStore);
        $label = sprintf('%s approval#%d kind=%s rental=%s requester=%s', $decision, $a->id, $kind, $p['rental_id'] ?? '-', $this->keyOfUserId((int) $a->requested_by_user_id));
        $res = $this->exec($label, $actor, $why !== null, (string) $why, fn () => $this->http('post', $route, ['decision_note' => 'stress'], $actor, $session));
        $this->maybeReplay($res, $label, $actor, true, fn () => $this->http('post', $route, ['decision_note' => 'stress'], $actor, $session));

        return true;
    }

    private function decisionForbidden(string $actor, object $a, array $p, string $decision): ?string
    {
        $role = $this->meta[$actor]['role'];
        $kind = (string) ($p['kind'] ?? 'create');
        if (in_array($role, ['admin', 'accounting'], true)) {
            return "role {$role} cannot decide approvals";
        }
        $self = (int) $a->requested_by_user_id === (int) $this->users[$actor]->id;
        if ($self && $kind === 'item_removal') {
            return 'self-decision of an item removal';
        }
        if ($self && $decision === 'approve' && in_array($kind, ['free', 'late_done'], true)) {
            return "self-approval of a {$kind} request";
        }
        if ($decision === 'approve' && $kind === 'delete' && ! empty($p['wrong_input'])) {
            if (! in_array($role, ['management', 'super_admin'], true)) {
                return 'wrong-input delete needs management';
            }
            if ($self) {
                return 'self-approval of a wrong-input delete';
            }
        }

        // The approval row is already decided: any second decision must be refused.
        $row = DB::table('rental_approval_requests')->where('id', $a->id)->first();
        if ($row && $row->status !== 'pending') {
            return 'already decided';
        }

        return null;
    }

    private function do_cancel(): bool
    {
        $r = $this->pickRental(fn ($x) => in_array($x->status, ['active', 'returned'], true), null, null);
        if (! $r) {
            return false;
        }
        $actor = $this->operatorFor((int) $r->store_id, 12);
        $role = $this->meta[$actor]['role'];
        $refund = false;
        $hazard = $this->cancelRefundHazard((int) $r->id);
        if (in_array($role, ['management', 'super_admin'], true) && $this->rng->chance(45)) {
            if ($hazard) {
                $this->excluded[$hazard] = ($this->excluded[$hazard] ?? 0) + 1;
            } else {
                $refund = true;
            }
        } elseif ($this->rng->chance(8)) {
            $refund = true; // non-management asking for a refund: must be refused (403)
        }
        [$forbidden, $why, $session] = $this->rentalCtx($actor, $r, true);
        if (! $forbidden && $r->status === 'returned') {
            [$forbidden, $why] = [true, 'a returned rent cannot be cancelled'];
        }
        if (! $forbidden && $refund && ! in_array($role, ['management', 'super_admin'], true)) {
            [$forbidden, $why] = [true, 'only management cancels with a refund'];
        }
        if (! $forbidden && $this->pendingOf((int) $r->id, ['edit'])) {
            [$forbidden, $why] = [true, 'cancel waits for the pending edit request'];
        }
        $payload = ['status' => 'cancelled'] + ($refund ? ['refund' => true] : []);
        $call = fn () => $this->http('post', route('rentals.status', $r->id), $payload, $actor, $session);
        $label = sprintf('cancel rental#%d refund=%s (paid %s)', $r->id, $refund ? 'yes' : 'no', $this->paidOf((int) $r->id));
        $res = $this->exec($label, $actor, $forbidden, $why, $call);
        $this->maybeReplay($res, $label, $actor, true, $call);

        return true;
    }

    private function do_reopen(): bool
    {
        $r = $this->pickRental(fn ($x) => $x->status === 'cancelled', null, null);
        if (! $r) {
            return false;
        }
        $actor = $this->operatorFor((int) $r->store_id, 25);
        $role = $this->meta[$actor]['role'];
        [$forbidden, $why, $session] = $this->rentalCtx($actor, $r, true);
        if (! $forbidden && ! in_array($role, ['management', 'super_admin'], true)) {
            [$forbidden, $why] = [true, 'only management reopens a cancelled rent'];
        }
        $label = sprintf('reopen rental#%d', $r->id);
        $this->exec($label, $actor, $forbidden, $why, fn () => $this->http('post', route('rentals.status', $r->id), ['status' => 'active'], $actor, $session));

        return true;
    }

    /** Prefer a rent that can really be picked up / returned, so the happy path is walked as well as the refusals. */
    private function pickReady(bool $pickedUp): ?object
    {
        $active = fn ($x) => $x->status === 'active';
        if ($this->rng->chance(75)) {
            $ready = $this->pickRental(fn ($x) => $x->status === 'active' && ($x->picked_up_at !== null) === $pickedUp
                && $this->paidOf((int) $x->id) + 0.01 >= $this->billOf($x) && ! $this->pendingOf((int) $x->id, ['edit']), null, null);
            if ($ready) {
                return $ready;
            }
        }

        return $this->pickRental($active, null, null);
    }

    private function do_pickup(): bool
    {
        $r = $this->pickReady(false);
        if (! $r) {
            return false;
        }
        $actor = $this->operatorFor((int) $r->store_id, 10);
        $pick = $r->picked_up_at === null || $this->rng->chance(25);
        [$forbidden, $why, $session] = $this->rentalCtx($actor, $r, true);
        $this->exec(sprintf('pickup rental#%d picked_up=%s', $r->id, $pick ? '1' : '0'), $actor, $forbidden, $why,
            fn () => $this->http('post', route('rentals.pickup', $r->id), ['picked_up' => $pick], $actor, $session));

        return true;
    }

    private function do_return(): bool
    {
        $r = $this->pickReady(true);
        if (! $r) {
            return false;
        }
        $actor = $this->operatorFor((int) $r->store_id, 10);
        [$forbidden, $why, $session] = $this->rentalCtx($actor, $r, true);
        $this->exec(sprintf('return rental#%d (picked_up=%s paid %s bill %s)', $r->id, $r->picked_up_at ? 'yes' : 'no', $this->paidOf((int) $r->id), $this->billOf($r)), $actor, $forbidden, $why,
            fn () => $this->http('post', route('rentals.status', $r->id), ['status' => 'returned'], $actor, $session));

        return true;
    }

    private function do_free(): bool
    {
        $r = $this->pickRental(fn ($x) => $x->status === 'active', null, null);
        if (! $r) {
            return false;
        }
        if (((float) ($r->extra_charge ?? 0)) > 0) {
            // C2-3: a free request leaves extra_charge in the bill, says "Rp 0" but refunds everything paid.
            $this->excluded['C2-3 (free request on a rent with an additional charge)'] = ($this->excluded['C2-3 (free request on a rent with an additional charge)'] ?? 0) + 1;

            return false;
        }
        $actor = $this->operatorFor((int) $r->store_id, 12);
        [$forbidden, $why, $session] = $this->rentalCtx($actor, $r, false);
        if (! $forbidden && $this->pendingOf((int) $r->id, ['free', 'edit', 'delete'])) {
            [$forbidden, $why] = [true, 'a request is already waiting on this rent'];
        }
        if (! $forbidden && $this->billOf($r) <= 0.004) {
            [$forbidden, $why] = [true, 'rent is already Rp 0'];
        }
        $call = fn () => $this->http('post', route('rentals.free-request', $r->id), ['reason' => 'endorse'], $actor, $session);
        $label = sprintf('free-request rental#%d (paid %s)', $r->id, $this->paidOf((int) $r->id));
        $res = $this->exec($label, $actor, $forbidden, $why, $call);
        $this->maybeReplay($res, $label, $actor, true, $call);

        return true;
    }

    private function do_late_done(): bool
    {
        $r = $this->pickRental(fn ($x) => $x->status === 'active', null, null);
        if (! $r) {
            return false;
        }
        $actor = $this->operatorFor((int) $r->store_id, 8);
        $type = $r->picked_up_at === null ? 'pickup' : 'return';
        if ($type === 'return') {
            // C2-10: an approved late "return" skips the Return gates. Only report a return that would pass them.
            $blocked = $this->paidOf((int) $r->id) + 0.01 < $this->billOf($r) || $this->pendingOf((int) $r->id, ['edit', 'late_done', 'free']);
            if ($blocked) {
                $this->excluded['C2-10 (late return on unpaid / pending rent)'] = ($this->excluded['C2-10 (late return on unpaid / pending rent)'] ?? 0) + 1;

                return false;
            }
        }
        $at = Carbon::now()->subHours(2)->toDateTimeString();
        $payload = ['type' => $type, 'note' => 'forgot to press', 'picked_up_at' => $at, 'picked_up_by' => $this->users['admin1']->id];
        if ($type === 'return') {
            $payload += ['returned_at' => Carbon::now()->subHour()->toDateTimeString(), 'returned_by' => $this->users['admin1']->id];
        }
        [$forbidden, $why, $session] = $this->rentalCtx($actor, $r, false);
        if (! $forbidden && $this->pendingOf((int) $r->id, ['late_done'])) {
            [$forbidden, $why] = [true, 'a late-done report is already waiting'];
        }
        $call = fn () => $this->http('post', route('rentals.late-done-request', $r->id), $payload, $actor, $session);
        $label = sprintf('late-done(%s) rental#%d', $type, $r->id);
        $res = $this->exec($label, $actor, $forbidden, $why, $call);
        $this->maybeReplay($res, $label, $actor, true, $call);

        return true;
    }

    private function do_delete(): bool
    {
        $r = $this->pickRental(fn ($x) => in_array($x->status, ['active', 'cancelled'], true), null, null);
        if (! $r) {
            return false;
        }
        $actor = $this->operatorFor((int) $r->store_id, 12);
        $role = $this->meta[$actor]['role'];
        $wrong = $role !== 'admin' && $this->rng->chance(70);
        $payload = $wrong ? ['wrong_input' => true, 'reason' => 'typed by mistake'] : [];
        [$forbidden, $why, $session] = $this->rentalCtx($actor, $r, false);
        $paid = $this->paidOf((int) $r->id);
        if (! $forbidden && $paid > 0.004 && ! $wrong) {
            [$forbidden, $why] = [true, 'a paid rent can only be deleted as wrong input'];
        }
        if (! $forbidden && $paid > 0.004 && $role === 'admin') {
            [$forbidden, $why] = [true, 'admin cannot delete a paid rent'];
        }
        if (! $forbidden && $this->pendingOf((int) $r->id, ['edit'])) {
            [$forbidden, $why] = [true, 'delete waits for the pending edit request'];
        }
        $label = sprintf('delete rental#%d wrong_input=%s (paid %s)', $r->id, $wrong ? 'yes' : 'no', $paid);
        $res = $this->exec($label, $actor, $forbidden, $why, fn () => $this->http('delete', route('rentals.destroy', $r->id), $payload, $actor, $session));
        $this->maybeReplay($res, $label, $actor, $res['status'] < 300, fn () => $this->http('delete', route('rentals.destroy', $r->id), $payload, $actor, $session));

        return true;
    }

    /** Shiftless payment -> management sends it to a shift owner -> owner accepts / rejects. */
    private function do_assign(): bool
    {
        $mode = $this->rng->weighted(['send' => 50, 'accept' => 30, 'reject' => 20]);
        if ($mode === 'send') {
            $payments = DB::table('rental_payment_records as p')->join('rentals as r', 'r.id', '=', 'p.rental_id')
                ->whereNull('r.deleted_at')->whereNull('p.shift_session_id')->where('p.amount', '>', 0)
                ->select('p.*', 'r.store_id as rstore')->get();
            if ($payments->isEmpty()) {
                return false;
            }
            $pay = $this->rng->pick($payments->all());
            $shifts = DB::table('shift_sessions')->whereNull('closed_at')->where('store_id', $pay->rstore)->get();
            if ($shifts->isEmpty()) {
                return false;
            }
            $shift = $this->rng->pick($shifts->all());
            $actor = $this->rng->chance(15) ? $this->rng->pick(array_keys($this->users)) : $this->rng->pick(['mgr1', 'mgr1b', 'mgr2', 'sa', 'acc']);
            $role = $this->meta[$actor]['role'];
            $session = $this->sessionStore($actor, (int) $pay->rstore);
            $forbidden = ! in_array($role, ['management', 'super_admin', 'accounting'], true);
            $why = "role {$role} cannot hand out shiftless payments";
            if (! $forbidden && $this->effectiveStore($actor, $session) !== (int) $pay->rstore) {
                [$forbidden, $why] = [true, 'payment is in another store'];
            }
            if (! $forbidden && (int) $shift->opened_by_user_id === (int) $this->users[$actor]->id) {
                [$forbidden, $why] = [true, 'cannot send to own shift'];
            }
            $call = fn () => $this->http('post', route('payments.assign-shift', $pay->id), ['shift_session_id' => $shift->id, 'note' => 'stress'], $actor, $session);
            $label = sprintf('assign-send payment#%d (%s) -> shift#%d', $pay->id, $pay->amount, $shift->id);
            $res = $this->exec($label, $actor, $forbidden, $why, $call);
            $this->maybeReplay($res, $label, $actor, true, $call);

            return true;
        }

        $pending = DB::table('rental_approval_requests')->where('status', 'pending')->where('payload->kind', 'payment_assign')->get();
        if ($pending->isEmpty()) {
            return false;
        }
        $a = $this->rng->pick($pending->all());
        $p = $this->decode($a->payload);
        $shift = DB::table('shift_sessions')->where('id', $p['shift_session_id'] ?? 0)->first();
        $ownerKey = $shift ? $this->keyOfUserId((int) $shift->opened_by_user_id) : null;
        $actor = ($ownerKey && ! $this->rng->chance(20)) ? $ownerKey : $this->rng->pick(array_keys($this->users));
        $session = $this->sessionStore($actor, (int) ($a->store_id ?: 1));
        $isOwner = $shift && (int) $shift->opened_by_user_id === (int) $this->users[$actor]->id;
        $forbidden = ! $isOwner;
        $why = 'only the shift owner decides a payment assignment';
        $route = route('payment-assign.'.$mode, $a->id);
        $call = fn () => $this->http('post', $route, ['note' => 'stress'], $actor, $session);
        $label = sprintf('assign-%s approval#%d payment#%s shift#%s owner=%s', $mode, $a->id, $p['payment_record_id'] ?? '?', $p['shift_session_id'] ?? '?', $ownerKey ?? '?');
        $res = $this->exec($label, $actor, $forbidden, $why, $call);
        $this->maybeReplay($res, $label, $actor, ! $forbidden, $call);

        return true;
    }

    private function do_shift(): bool
    {
        $key = $this->rng->pick(['admin1', 'admin2', 'admin3', 'sup1', 'acc']);
        $role = $this->meta[$key]['role'];
        $home = (int) $this->meta[$key]['home'];
        $open = $this->hasOpenShift($key);
        if ($open) {
            $this->exec("shift-close {$key}", $key, $role === 'accounting', 'accounting cannot close shifts',
                fn () => $this->http('post', route('shifts.close'), ['closing_note' => 'stress'], $key, $home));
        } else {
            $this->exec("shift-open {$key}", $key, $role === 'accounting', 'accounting cannot open shifts',
                fn () => $this->http('post', route('shifts.open'), ['period' => $this->rng->pick(['morning', 'afternoon'])], $key, $home));
        }

        return true;
    }

    /**
     * Practice (tutorial) create. It lands in tutorial_* tables; nothing real may change.
     * Run by roles that never need a real shift (C6-03 excluded) and never touched again (P4/P4b/C6-02 excluded).
     */
    private function do_practice(): bool
    {
        $actor = $this->rng->pick(['sup1', 'mgr1', 'mgr2', 'sa']);
        if ($this->hasOpenShift($actor)) {
            // N1 (new, see 04a report): a practice create by someone with a REAL open shift answers 500 (FK to tutorial_shift_sessions).
            $this->excluded['N1 (practice create while a real shift is open)'] = ($this->excluded['N1 (practice create while a real shift is open)'] ?? 0) + 1;

            return false;
        }
        $store = $this->effectiveStore($actor, $this->rng->pick([1, 2]));
        $start = Carbon::now()->startOfDay()->addDays(20);
        $payload = [
            'tutorial_practice' => true, 'outfit_ids' => [$this->outfits[$store][0]], 'customer_name' => 'PRACTICE', 'phone' => '0811',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => '100000',
        ];
        $this->exec('practice-create store'.$store, $actor, true, 'practice data must not touch real tables',
            fn () => $this->http('post', route('rentals.store'), $payload, $actor, $this->sessionStore($actor, $store)), practice: true);

        return true;
    }

    private function do_approval_cancel(): bool
    {
        $pending = DB::table('rental_approval_requests')->where('status', 'pending')->get()
            ->filter(fn ($a) => (($this->decode($a->payload)['kind'] ?? 'create') !== 'payment_assign'))->values();
        if ($pending->isEmpty()) {
            return false;
        }
        $a = $this->rng->pick($pending->all());
        $requester = $this->keyOfUserId((int) $a->requested_by_user_id);
        $actor = ($requester && ! $this->rng->chance(25)) ? $requester : $this->rng->pick(array_keys($this->users));
        $forbidden = $requester !== $actor;
        $call = fn () => $this->http('post', route('approvals.rentals.cancel', $a->id), [], $actor, $this->sessionStore($actor, (int) ($a->store_id ?: 1)));
        $label = sprintf('approval-cancel#%d requester=%s', $a->id, $requester ?? '?');
        $res = $this->exec($label, $actor, $forbidden, 'only the requester cancels a request', $call);
        $this->maybeReplay($res, $label, $actor, ! $forbidden, $call);

        return true;
    }

    // =====================================================================================
    // Execution + checks around one request
    // =====================================================================================

    /**
     * @param  callable(): array{status: int, json: ?array, body: string}  $call
     * @return array{status: int, json: ?array, body: string}
     */
    private function exec(string $label, string $actor, bool $forbidden, string $why, callable $call, bool $practice = false, bool $moneyOnly = false): array
    {
        $this->step++;
        $snap = fn () => $moneyOnly ? $this->moneySnapshot() : $this->snapshot();
        $before = ($forbidden || $practice) ? $snap() : null;
        try {
            $res = $call();
        } catch (\Throwable $e) {
            $res = ['status' => 599, 'json' => null, 'body' => get_class($e).': '.$e->getMessage()];
        }
        $after = ($forbidden || $practice) ? $snap() : null;

        $actionName = strtok($label, ' ') ?: 'x';
        $this->statuses[$actionName.' '.$res['status']] = ($this->statuses[$actionName.' '.$res['status']] ?? 0) + 1;
        $detail = $res['json'] !== null ? json_encode(array_intersect_key($res['json'], array_flip(['ok', 'mode', 'message', 'status', 'refund', 'errors']))) : $res['body'];
        $this->trace[] = sprintf('#%d [%s/%s]%s %s -> %d %s', $this->step, $actor, $this->meta[$actor]['role'], $forbidden ? ' (MUST-NOT-CHANGE: '.$why.')' : '', $label, $res['status'], mb_substr((string) $detail, 0, 220));

        if ($res['status'] >= 500) {
            $this->violate('I0-server-error', "HTTP {$res['status']} for [{$label}]: ".mb_substr($res['body'], 0, 300));
        }
        if (($forbidden || $practice) && $before !== $after) {
            $changed = array_keys(array_filter($after, fn ($v, $k) => $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
            $this->violate($practice ? 'F2-practice-touched-real-tables' : 'F1-forbidden-changed-state',
                "[{$label}] by {$actor} ({$why}) answered {$res['status']} but changed: ".implode(', ', $changed));
        }

        return $res;
    }

    /** Optionally repeat the very same request: the repeat must not change anything. */
    private function maybeReplay(array $res, string $label, string $actor, bool $eligible, callable $call): void
    {
        if (! $eligible || $res['status'] >= 300 && $res['status'] !== 302 || ! $this->rng->chance(30)) {
            return;
        }
        $this->exec('REPLAY '.$label, $actor, true, 'identical request sent twice', $call);
    }

    public function violate(string $id, string $msg): void
    {
        $this->violations[] = ['id' => $id, 'msg' => $msg, 'step' => $this->step, 'trace_len' => count($this->trace)];
    }

    // =====================================================================================
    // HTTP + snapshots
    // =====================================================================================

    /** @return array{status: int, json: ?array, body: string, res: \Illuminate\Testing\TestResponse} */
    public function http(string $method, string $uri, array $data, string $actorKey, int $store): array
    {
        // Production starts every request in a fresh PHP process: StoreContext is empty until the set.store
        // middleware runs. The static would otherwise leak the PREVIOUS request's store into route-model
        // binding (the {approval} binding runs before set.store) and produce random 404s that cannot happen live.
        StoreContext::set(null, false);
        $this->t->actingAs($this->users[$actorKey]);
        $this->t->withSession(['current_store_id' => $store]);
        $res = match ($method) {
            'put' => $this->t->putJson($uri, $data),
            'delete' => $this->t->deleteJson($uri, $data),
            'get' => $this->t->getJson($uri),
            default => $this->t->postJson($uri, $data),
        };
        $json = null;
        try {
            $decoded = $res->json();
            $json = is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
        }

        $body = mb_substr((string) $res->getContent(), 0, 400);
        if ($res->getStatusCode() >= 300 && $res->getStatusCode() < 400) {
            $session = app('session.store');
            $errors = $session->get('errors');
            if ($errors instanceof \Illuminate\Support\ViewErrorBag) {
                $errors = $errors->getBag('default')->all();
            }
            $body = 'redirect; errors='.json_encode($errors ?: []).'; flash='.json_encode($session->get('success') ?? $session->get('error'));
        }

        return ['status' => $res->getStatusCode(), 'json' => $json, 'body' => $body, 'res' => $res];
    }

    /** md5 of every table a refused / replayed request must leave alone. */
    public function snapshot(): array
    {
        $out = [];
        foreach (['rentals', 'rental_outfit', 'rental_payment_records', 'rental_approval_requests', 'journal_entries', 'journal_entry_lines', 'shift_sessions'] as $table) {
            $query = DB::table($table);
            $rows = $table === 'rental_outfit' ? $query->orderBy('rental_id')->orderBy('outfit_id')->get() : $query->orderBy('id')->get();
            $out[$table] = md5(json_encode($rows->map(function ($row) {
                $row = (array) $row;
                unset($row['updated_at']);

                return $row;
            })->all()));
        }

        return $out;
    }

    /** Only the columns that carry money / status, for requests that may legitimately touch metadata (handled_by, method). */
    public function moneySnapshot(): array
    {
        $out = [];
        $out['rentals'] = md5(json_encode(DB::table('rentals')->orderBy('id')->get(['id', 'status', 'amount_paid', 'payment_kind', 'discount', 'discount_type', 'extra_charge', 'down_payment_amount', 'deleted_at', 'picked_up_at'])->all()));
        foreach (['rental_outfit', 'rental_payment_records', 'journal_entries', 'journal_entry_lines'] as $table) {
            $q = DB::table($table);
            $rows = $table === 'rental_outfit' ? $q->orderBy('rental_id')->orderBy('outfit_id')->get() : $q->orderBy('id')->get();
            $out[$table] = md5(json_encode($rows->map(function ($row) {
                $row = (array) $row;
                unset($row['updated_at']);

                return $row;
            })->all()));
        }
        $out['shift_sessions'] = md5(json_encode(DB::table('shift_sessions')->orderBy('id')->get(['id', 'closed_at', 'total_revenue', 'total_transactions'])->all()));

        return $out;
    }

    /** The money state used by the idempotency tests. */
    public function moneyState(): array
    {
        return [
            'payments' => DB::table('rental_payment_records')->orderBy('id')->get(['id', 'rental_id', 'shift_session_id', 'amount', 'payment_kind'])->map(fn ($r) => (array) $r)->all(),
            'journal' => [DB::table('journal_entries')->count(), round((float) DB::table('journal_entry_lines')->sum('debit'), 2), DB::table('journal_entry_lines')->count()],
            'rentals' => DB::table('rentals')->orderBy('id')->get(['id', 'status', 'amount_paid', 'discount', 'discount_type', 'deleted_at', 'picked_up_at'])->map(fn ($r) => (array) $r)->all(),
            'pivots' => DB::table('rental_outfit')->orderBy('rental_id')->orderBy('outfit_id')->get()->map(fn ($r) => (array) $r)->all(),
            'shifts' => DB::table('shift_sessions')->orderBy('id')->get(['id', 'closed_at', 'total_revenue', 'total_transactions'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    // =====================================================================================
    // Read helpers (raw tables, no global scopes)
    // =====================================================================================

    public function decode(?string $json): array
    {
        $d = json_decode((string) $json, true);

        return is_array($d) ? $d : [];
    }

    public function paidOf(int $rentalId): float
    {
        return round((float) DB::table('rental_payment_records')->where('rental_id', $rentalId)
            ->where(fn ($q) => $q->whereNull('payment_kind')->orWhere('payment_kind', '!=', 'overdue'))->sum('amount'), 2);
    }

    public function calDays(object $r): int
    {
        return (int) round((strtotime(substr((string) $r->end_date, 0, 10)) - strtotime(substr((string) $r->start_date, 0, 10))) / 86400) + 1;
    }

    /** Independent rent bill: pivot prices x 3-day blocks, item discounts, order discount, extra charge. */
    public function billOf(object $r): float
    {
        $pivots = DB::table('rental_outfit')->where('rental_id', $r->id)->get();

        return $this->billFromPivots($r, $pivots);
    }

    public function billFromPivots(object $r, Collection $pivots): float
    {
        $blocks = max(1, intdiv($this->calDays($r), 3));
        $sum = 0.0;
        foreach ($pivots as $p) {
            $gross = (float) $p->price_incl_tax * $blocks;
            $d = (float) ($p->discount ?? 0);
            $net = $d > 0 ? (($p->discount_type ?? 'fixed') === 'percent' ? $gross - $gross * $d / 100 : $gross - $d) : $gross;
            $sum += max(0.0, $net);
        }
        $od = (float) ($r->discount ?? 0);
        $after = $od > 0 ? (($r->discount_type ?? 'fixed') === 'percent' ? $sum - $sum * $od / 100 : $sum - $od) : $sum;

        return round(max(0.0, $after) + max(0.0, (float) ($r->extra_charge ?? 0)), 2);
    }

    /** Bill of a rent that does not exist yet (catalogue price, tax-inclusive). */
    private function plannedBill(int $store, array $outfitIds, int $days, ?string $discount, string $type, array $itemDiscounts): float
    {
        $blocks = max(1, intdiv($days, 3));
        $sum = 0.0;
        foreach ($outfitIds as $id) {
            $price = (float) DB::table('outfits')->where('id', $id)->value('price');
            $gross = $price * $blocks;
            $sum += max(0.0, $gross - (float) ($itemDiscounts[$id] ?? 0));
        }
        $od = (float) ($discount ?? 0);
        $after = $od > 0 ? ($type === 'percent' ? $sum - $sum * $od / 100 : $sum - $od) : $sum;

        return round(max(0.0, $after), 2);
    }

    /** @param list<string> $kinds */
    public function pendingOf(int $rentalId, array $kinds): ?object
    {
        return DB::table('rental_approval_requests')->where('status', 'pending')
            ->whereIn('payload->kind', $kinds)->where('payload->rental_id', $rentalId)->first();
    }

    private function hasOpenShift(string $key): bool
    {
        return DB::table('shift_sessions')->where('opened_by_user_id', $this->users[$key]->id)->whereNull('closed_at')->exists();
    }

    private function keyOfUserId(int $id): ?string
    {
        foreach ($this->users as $key => $user) {
            if ((int) $user->id === $id) {
                return $key;
            }
        }

        return null;
    }

    /** Cash that physically left for a rent (refund / cancel-refund / FREE entries; reversals netted). Journal only: rentals.amount_paid is never reduced by it. */
    public function cashBack(int $rentalId): float
    {
        $ids = DB::table('accounts')->whereIn('code', ['1100', '1200'])->pluck('id');
        $approvalIds = DB::table('rental_approval_requests')->where('payload->rental_id', $rentalId)->pluck('id');
        $entryIds = DB::table('journal_entries')->where(function ($q) use ($rentalId, $approvalIds) {
            $q->where(fn ($w) => $w->whereIn('reference_type', ['rental_refund', 'rental_cancellation'])->where('reference_id', $rentalId))
                ->orWhere(fn ($w) => $w->where('reference_type', 'item_removal_refund')->whereIn('reference_id', $approvalIds));
        })->pluck('id');
        if ($entryIds->isEmpty() || $ids->isEmpty()) {
            return 0.0;
        }

        return round((float) DB::table('journal_entry_lines')->whereIn('journal_entry_id', $entryIds)->whereIn('account_id', $ids)->sum(DB::raw('credit - debit')), 2);
    }

    /** Known hazards of cancel-with-refund (C2-1, C3-6): returns the finding id when the refund must be skipped. */
    private function cancelRefundHazard(int $rentalId): ?string
    {
        if ($this->known('C3-6')) {
            return null;
        }
        if ($this->pendingOf($rentalId, ['item_removal'])) {
            return 'C2-1 (cancel-with-refund while item removal pending)';
        }
        $refunded = DB::table('journal_entries')->where(function ($q) use ($rentalId) {
            $q->where(fn ($w) => $w->where('reference_type', 'rental_refund')->where('reference_id', $rentalId))
                ->orWhere(fn ($w) => $w->where('reference_type', 'item_removal_refund')->whereIn('reference_id', DB::table('rental_approval_requests')->where('payload->rental_id', $rentalId)->pluck('id')));
        })->exists();
        if ($refunded) {
            return 'C3-6 (cancel-with-refund after an earlier refund)';
        }
        if ($this->pendingOf($rentalId, ['free'])) {
            return 'C3-6/free (cancel-with-refund while a free request is pending)';
        }

        return null;
    }

    // =====================================================================================
    // Choosing actors / targets
    // =====================================================================================

    private function isPrivileged(string $key): bool
    {
        return in_array($this->meta[$key]['role'], self::PRIVILEGED, true);
    }

    /** The store a request really runs in: privileged roles follow the session, the rest are pinned to their home. */
    private function effectiveStore(string $key, int $sessionStore): int
    {
        return $this->isPrivileged($key) ? $sessionStore : (int) $this->meta[$key]['home'];
    }

    /** Which store to put in the session for a request aimed at $targetStore (sometimes the wrong one). */
    private function sessionStore(string $key, int $targetStore): int
    {
        return $targetStore;
    }

    /** @param list<string> $natural */
    private function actor(array $natural, int $oddPercent): string
    {
        return $this->rng->chance($oddPercent) ? $this->rng->pick(array_keys($this->users)) : $this->rng->pick($natural);
    }

    /** An operator that normally works in the rent's store; with $oddPercent anyone at all. */
    private function operatorFor(int $store, int $oddPercent): string
    {
        if ($this->rng->chance($oddPercent)) {
            return $this->rng->pick(array_keys($this->users));
        }
        $natural = array_values(array_filter(self::OPERATORS, fn ($k) => $this->isPrivileged($k) || $this->meta[$k]['home'] === $store));
        // The front desk is mostly admins with an open shift: that is where shift totals matter.
        $admins = array_values(array_filter($natural, fn ($k) => $this->meta[$k]['role'] === 'admin' && $this->hasOpenShift($k)));
        if ($admins !== [] && $this->rng->chance(55)) {
            return $this->rng->pick($admins);
        }

        return $this->rng->pick($natural);
    }

    /**
     * Session store for a request aimed at $rental, and whether the app must refuse it.
     * The {rental} route binding looks the rent up among ALL stores the user may access
     * (AppServiceProvider), so management / super admin / accounting reach any store whatever
     * their session says; admin and supervisor are pinned to their own store (404 elsewhere).
     * Privileged roles are always given the rent's own store as session: acting from the
     * "wrong" store is the known C4-02 problem (closed-shift recount under the caller's store).
     *
     * @return array{0: bool, 1: string, 2: int} [must be refused, why, session store]
     */
    private function rentalCtx(string $actor, object $rental, bool $needsShift): array
    {
        $store = (int) $rental->store_id;
        $role = $this->meta[$actor]['role'];
        if ($role === 'accounting') {
            return [true, 'accounting cannot operate rents', $store];
        }
        if (! $this->isPrivileged($actor) && $this->meta[$actor]['home'] !== $store) {
            // P4/P4b/C2-6/C6-02: when the actor owns a practice rent with the same id, the {rental} binding
            // silently falls back to THAT practice rent and the action leaks into real data. Known; skip.
            if (DB::table('tutorial_rentals')->where('id', $rental->id)->where('created_by_user_id', $this->users[$actor]->id)->exists()) {
                throw new SkipAction('P4/P4b/C2-6/C6-02 (practice rent with the same id as a real rent of another store)');
            }

            return [true, 'rent belongs to another store than the actor works in', $store];
        }
        if ($needsShift && $role === 'admin' && ! $this->hasOpenShift($actor)) {
            return [true, 'admin without an open shift', $store];
        }

        return [false, '', $store];
    }

    /**
     * @param  callable(object): bool  $filter
     * @param  ?callable(object): bool  $excludedProbe  when set and matching, the candidate was dropped for a known finding
     */
    private function pickRental(callable $filter, ?string $knownId, ?callable $excludedProbe): ?object
    {
        $all = DB::table('rentals')->whereNull('deleted_at')->orderBy('id')->get();
        $candidates = $all->filter($filter)->values();
        if ($knownId !== null && $excludedProbe !== null && $all->contains($excludedProbe)) {
            $this->excluded[$knownId] = ($this->excluded[$knownId] ?? 0) + 1;
        }
        if ($candidates->isEmpty()) {
            return null;
        }

        return $this->rng->pick($candidates->all());
    }

    /**
     * Outfits of $store that no active rent overlaps in [start-2, end+2] (so cool-down never blocks).
     * With $strict=false a random outfit is allowed (to exercise the conflict refusal).
     *
     * @param  list<int>  $exclude
     * @return list<int>
     */
    private function pickFreeOutfits(int $store, Carbon $start, Carbon $end, int $count, bool $strict, array $exclude = []): array
    {
        $pool = array_values(array_diff($this->outfits[$store], $exclude));
        $busy = DB::table('rental_outfit as rs')->join('rentals as r', 'r.id', '=', 'rs.rental_id')
            ->whereNull('r.deleted_at')->whereIn('r.status', ['active'])
            ->where('r.end_date', '>=', $start->copy()->subDays(2)->toDateString())
            ->where('r.start_date', '<=', $end->copy()->addDays(2)->toDateString())
            ->pluck('rs.outfit_id')->map(fn ($v) => (int) $v)->all();
        $free = $strict ? array_values(array_diff($pool, $busy)) : $pool;
        if ($free === []) {
            return [];
        }
        $picked = [];
        for ($i = 0; $i < $count && $free !== []; $i++) {
            $one = $this->rng->pick($free);
            $picked[] = $one;
            $free = array_values(array_diff($free, [$one]));
        }

        return $picked;
    }

    /** What the edit modal posts for an unchanged rent (Pay / Save with nothing changed). */
    public function basePayload(object $r): array
    {
        $pivots = DB::table('rental_outfit')->where('rental_id', $r->id)->orderBy('outfit_id')->get();
        $p = [
            'outfit_ids' => $pivots->pluck('outfit_id')->map(fn ($v) => (int) $v)->all(),
            'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => substr((string) $r->start_date, 0, 10), 'end_date' => substr((string) $r->end_date, 0, 10),
            'payment_method' => $r->payment_method ?: 'cash', 'payment_kind' => $r->payment_kind ?: 'down_payment',
            'discount' => ((float) ($r->discount ?? 0)) > 0 ? (string) (int) $r->discount : '',
            'discount_type' => $r->discount_type ?: 'fixed', 'discount_note' => $r->discount_note,
            'notes' => $r->notes, 'instagram' => $r->instagram,
            'payment_entry' => 'increment', 'payment_amount_add' => '0',
            'extra_charge' => ((float) ($r->extra_charge ?? 0)) > 0 ? (string) (int) $r->extra_charge : '',
        ];
        foreach ($pivots as $pv) {
            $p['item_discounts'][$pv->outfit_id] = ((float) ($pv->discount ?? 0)) > 0 ? (string) (int) $pv->discount : '';
            $p['item_discount_types'][$pv->outfit_id] = $pv->discount_type ?: 'fixed';
            if (((float) ($pv->discount ?? 0)) > 0) {
                $p['item_discount_notes'][$pv->outfit_id] = $pv->discount_note ?: 'item promo';
            }
        }

        return $p;
    }

    /** What the walk actually exercised (for the report). */
    public function coverage(): array
    {
        $cov = [];
        foreach (DB::table('rental_approval_requests')->get() as $a) {
            $k = 'approval '.($this->decode($a->payload)['kind'] ?? 'create').' '.$a->status;
            $cov[$k] = ($cov[$k] ?? 0) + 1;
        }
        foreach (DB::table('journal_entries')->get(['reference_type', 'is_reversal']) as $j) {
            $k = 'journal '.($j->reference_type ?? 'manual').($j->is_reversal ? ' (reversal)' : '');
            $cov[$k] = ($cov[$k] ?? 0) + 1;
        }
        foreach (DB::table('rentals')->get(['status', 'deleted_at']) as $r) {
            $k = 'rental '.($r->deleted_at ? 'deleted' : $r->status);
            $cov[$k] = ($cov[$k] ?? 0) + 1;
        }
        $cov['payment records'] = DB::table('rental_payment_records')->count();
        $cov['payment records negative'] = DB::table('rental_payment_records')->where('amount', '<', 0)->count();
        $cov['payment records without shift'] = DB::table('rental_payment_records')->whereNull('shift_session_id')->count();
        $cov['closed shifts'] = DB::table('shift_sessions')->whereNotNull('closed_at')->count();

        return $cov;
    }

    /** One-line state of every rent, for the failure report. */
    public function describeState(): string
    {
        $lines = [];
        foreach (DB::table('rentals')->orderBy('id')->get() as $r) {
            $lines[] = sprintf('  rental#%d store%d %s%s %s..%s bill=%s paid=%s amount_paid=%s kind=%s picked=%s',
                $r->id, $r->store_id, $r->status, $r->deleted_at ? '(DELETED)' : '', substr($r->start_date, 0, 10), substr($r->end_date, 0, 10),
                $this->billOf($r), $this->paidOf((int) $r->id), (float) $r->amount_paid, $r->payment_kind, $r->picked_up_at ? 'y' : 'n');
        }

        return implode("\n", $lines);
    }
}

<?php

namespace Tests\Repro;

use App\Models\Account;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for patch 05-escape-attribute-injection (findings C7-07, C7-08).
 * FAILS on the current code, PASSES once the patch is applied.
 *
 * It behaves like a browser as far as possible without one: the HTML is parsed with DOMDocument
 * (entities are decoded exactly like an HTML parser does), then the resulting JavaScript is run in Node with
 * stubbed globals. Needs `node` on PATH, otherwise the tests are skipped.
 * Not part of the default suites; run by path:
 *   php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Repro/Patch05AttributeEscapingTest.php
 */
class Patch05AttributeEscapingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        if (trim((string) @shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is not on PATH');
        }
        StoreContext::set(null);
        $this->store = Store::create(['name' => 'Demo Store One', 'code' => 'store-a', 'is_active' => true]);
        StoreContext::set($this->store->id);
        StoreCache::forever('pricing_tax_percent', 0);
        StoreCache::forever('pricing_prices_include_tax', false);
    }

    protected function tearDown(): void
    {
        StoreContext::set(null);
        parent::tearDown();
    }

    /** Runs $code in Node with $input as JSON on stdin; the script must print one JSON document. */
    private function node(string $code, array $input = []): array
    {
        $proc = proc_open(['node', '-e', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode($input));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        $decoded = json_decode((string) $out, true);
        $this->assertIsArray($decoded, 'node produced no JSON. stderr: '.$err);

        return $decoded;
    }

    /** Evaluates an inline handler attribute (already entity-decoded by the HTML parser) with a stubbed window/event; bare names resolve on the stub window like in a browser. */
    private function runHandler(string $attribute, string $fnName): array
    {
        return $this->node(<<<'JS'
const input = JSON.parse(require('fs').readFileSync(0, 'utf8'));
const calls = [];
const win = { __pwned: 0 };
win[input.fn] = function () { calls.push(Array.from(arguments).map(String)); return false; };
let error = null;
try { new Function('event', 'window', 'with (window) {' + input.attr + '\n}')({}, win); } catch (e) { error = String(e); }
console.log(JSON.stringify({ calls, pwned: win.__pwned, error }));
JS, ['attr' => $attribute, 'fn' => $fnName]);
    }

    /** @return list<string> every onsubmit attribute value on the page, as a browser would decode it */
    private function onsubmitAttributes(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $found = [];
        foreach ((new \DOMXPath($dom))->query('//*[@onsubmit]') as $node) {
            $found[] = $node->getAttribute('onsubmit');
        }

        return $found;
    }

    /**
     * Names that break inline handlers: a plain apostrophe (also breaks the button for honest users) and a payload
     * that closes the string and runs code inside the handler's own call expression.
     *
     * @return array<string, array{0: string, 1: string}> [name, marker that appears in the handler]
     */
    public static function hostileNames(): array
    {
        return [
            'apostrophe' => ["O'Brien", 'Brien'],
            'code injection' => ["Evil', window.__pwned=1), ('", 'Evil'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileNames')]
    public function test_users_page_delete_confirm_survives_quotes_in_names(string $name, string $marker): void
    {
        User::factory()->create(['role' => 'admin', 'name' => $name]);
        $manager = User::factory()->create(['role' => 'management']);

        $html = $this->actingAs($manager)->withSession(['current_store_id' => $this->store->id])
            ->get(route('users.index'))->assertOk()->getContent();

        $handlers = array_values(array_filter($this->onsubmitAttributes($html), fn ($a) => str_contains($a, 'Delete') && str_contains($a, $marker)));
        $this->assertCount(1, $handlers, 'one Delete confirm for the target user');

        $result = $this->runHandler($handlers[0], 'rentalConfirmSubmit');
        $this->assertNull($result['error'], 'the handler must be valid JavaScript: '.($result['error'] ?? ''));
        $this->assertSame(0, $result['pwned'], 'user-controlled text must not run as code');
        $this->assertSame('Delete '.$name.'?', $result['calls'][0][1] ?? null, 'the dialog shows the name literally');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileNames')]
    public function test_users_page_pin_reset_confirm_survives_quotes_in_names(string $name, string $marker): void
    {
        $target = User::factory()->create(['role' => 'admin', 'name' => $name]);
        $target->forceFill(['stock_opname_pin' => bcrypt('123456')])->save();
        $manager = User::factory()->create(['role' => 'management']);

        $html = $this->actingAs($manager)->withSession(['current_store_id' => $this->store->id])
            ->get(route('users.index'))->assertOk()->getContent();

        $handlers = array_values(array_filter($this->onsubmitAttributes($html), fn ($a) => str_contains($a, 'opname PIN') && str_contains($a, $marker)));
        $this->assertCount(1, $handlers, 'one PIN-reset confirm for the target user (user must have a PIN set)');

        $result = $this->runHandler($handlers[0], 'rentalConfirmSubmit');
        $this->assertNull($result['error'], 'the handler must be valid JavaScript: '.($result['error'] ?? ''));
        $this->assertSame(0, $result['pwned']);
        $this->assertStringContainsString('The opname PIN of '.$name.' will be deleted', $result['calls'][0][1] ?? '');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileNames')]
    public function test_accounts_page_delete_confirm_survives_quotes_in_names(string $name, string $marker): void
    {
        Account::create(['code' => '9100', 'name' => $name, 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_equivalent' => false, 'is_active' => true]);
        $manager = User::factory()->create(['role' => 'management']);

        $html = $this->actingAs($manager)->withSession(['current_store_id' => $this->store->id])
            ->get(route('accounts.index'))->assertOk()->getContent();

        $handlers = array_values(array_filter($this->onsubmitAttributes($html), fn ($a) => str_contains($a, 'Delete') && str_contains($a, $marker)));
        $this->assertCount(1, $handlers, 'one Delete confirm for the new account');

        $result = $this->runHandler($handlers[0], 'rentalConfirmSubmit');
        $this->assertNull($result['error'], 'the handler must be valid JavaScript: '.($result['error'] ?? ''));
        $this->assertSame(0, $result['pwned']);
        $this->assertSame('Delete '.$name.'? This cannot be undone.', $result['calls'][0][1] ?? null);
    }

    /**
     * C7-07: the global activity feed builds a button per log row in JavaScript. A customer name with a double quote
     * must neither add attributes (stored XSS) nor break the click for ordinary names.
     */
    public function test_activity_log_button_survives_hostile_and_ordinary_names(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->stores()->attach($this->store->id);
        $html = $this->actingAs($admin)->withSession(['current_store_id' => $this->store->id])
            ->get('/dashboard')->assertOk()->getContent();

        // The partial's <script>: from "var _activityLogPanelCache" up to its closing tag.
        $this->assertSame(1, preg_match('#<script>\s*var _activityLogPanelCache.*?</script>#s', $html, $m), 'activity log panel script is on the page');

        $built = $this->node(<<<'JS'
const input = JSON.parse(require('fs').readFileSync(0, 'utf8'));
const els = {};
function el(id) { return els[id] || (els[id] = { id, innerHTML: '', textContent: '', href: '', classList: { add() {}, remove() {}, toggle() {} }, addEventListener() {}, setAttribute() {}, focus() {} }); }
global.document = { getElementById: el, querySelector: () => null, querySelectorAll: () => [], addEventListener() {}, body: el('body') };
global.window = global; global.fetch = () => new Promise(() => {});
(0, eval)(input.script);
const out = [];
for (const name of input.names) {
  els.activityLogPanelBody = undefined;
  renderActivityLogPanel({ logs: [{ action: 'created', action_label: 'Created', rental_id: 5, rental_customer_name: name, user_name: 'u', time_full: 't', time_human: 'h', note: null, changes: [] }] });
  out.push(els.activityLogPanelBody.innerHTML);
}
console.log(JSON.stringify(out));
JS, [
            'script' => preg_replace('#^<script>|</script>$#', '', $m[0]),
            'names' => ['Budi', 'a" onmouseover="window.__pwned=1" x="'],
        ]);

        foreach ([['Budi', $built[0]], ['a" onmouseover="window.__pwned=1" x="', $built[1]]] as [$name, $fragment]) {
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="utf-8"?><div>'.$fragment.'</div>');
            $buttons = (new \DOMXPath($dom))->query('//button');
            $this->assertSame(1, $buttons->length, 'one rental button');
            $attrs = [];
            foreach ($buttons->item(0)->attributes as $a) {
                $attrs[] = $a->name;
            }
            sort($attrs);
            $this->assertSame(['class', 'onclick', 'type'], $attrs, 'no injected attributes for name '.$name);

            $result = $this->runHandler($buttons->item(0)->getAttribute('onclick'), 'openActivityLogPanel');
            $this->assertNull($result['error'], 'onclick must be valid JavaScript for name '.$name.': '.($result['error'] ?? ''));
            $this->assertSame(['5', $name], $result['calls'][0] ?? null, 'click opens the rental panel with the exact name');
        }
    }
}

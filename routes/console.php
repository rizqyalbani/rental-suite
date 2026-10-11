<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use App\Support\StoreCache;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('db:check', function () {
    $default = (string) (config('database.default') ?? '');
    $this->line('Connection: '.$default);

    if ($default === '') {
        $this->error('No database.default configured.');
        return self::FAILURE;
    }

    try {
        $pdo = DB::connection()->getPdo();
        $name = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $db = (string) (DB::connection()->getDatabaseName() ?? '');
        $this->info('Connected.');
        $this->line('Driver: '.$name);
        $this->line('Database: '.$db);
        $ok = DB::selectOne('select 1 as ok');
        if (! $ok || ((int) ($ok->ok ?? 0)) !== 1) {
            $this->error('Basic query failed.');
            return self::FAILURE;
        }
    } catch (\Throwable $e) {
        $this->error('Connection failed: '.$e->getMessage());
        return self::FAILURE;
    }

    return self::SUCCESS;
})->purpose('Verify DB connection from .env / config');

Artisan::command('db:sync {--no-backup : Skip automatic backup even on MySQL} {--verify : Run a safe schema sync verification (add column + rollback)}', function () {
    $log = Log::build([
        'driver' => 'single',
        'path' => storage_path('logs/schema-sync.log'),
        'level' => env('LOG_LEVEL', 'debug'),
        'replace_placeholders' => true,
    ]);

    $log->info('db:sync start', ['app_env' => App::environment(), 'db' => config('database.default')]);

    $check = Artisan::call('db:check');
    if ($check !== self::SUCCESS) {
        $log->error('db:check failed', ['output' => Artisan::output()]);
        $this->error('DB check failed. See storage/logs/schema-sync.log');
        return self::FAILURE;
    }

    $default = (string) (config('database.default') ?? '');
    if ($default === 'mysql' && ! $this->option('no-backup')) {
        $c = (array) (config('database.connections.mysql') ?? []);
        $db = (string) ($c['database'] ?? '');
        $host = (string) ($c['host'] ?? '127.0.0.1');
        $port = (string) (($c['port'] ?? 3306));
        $user = (string) ($c['username'] ?? '');
        $pass = (string) ($c['password'] ?? '');
        $socket = trim((string) ($c['unix_socket'] ?? ''));

        if ($db !== '' && $user !== '') {
            $stamp = Carbon::now()->format('Y-m-d_H-i-s');
            $outPath = base_path('db-backup/schema_sync_'.$db.'_'.$stamp.'.sql.gz');
            if (! is_dir(dirname($outPath))) {
                mkdir(dirname($outPath), 0755, true);
            }

            $cmd = [
                'mysqldump',
                '--single-transaction',
                '--quick',
                '--set-charset',
                '--default-character-set=utf8mb4',
                '--skip-comments',
                '--routines',
                '--triggers',
                '--events',
                '--hex-blob',
                '--no-tablespaces',
                '-h', $host,
                '-P', $port,
                '-u', $user,
            ];
            if ($socket !== '') {
                $cmd[] = '--socket='.$socket;
            }
            $cmd[] = $db;

            try {
                $env = getenv();
                $env['MYSQL_PWD'] = $pass;
                $proc = new Process($cmd, base_path(), $env);
                $proc->setTimeout(3600);
                $proc->mustRun();
                file_put_contents($outPath, gzencode($proc->getOutput(), 9));

                $shaPath = $outPath.'.sha256';
                $sha = hash_file('sha256', $outPath);
                file_put_contents($shaPath, $sha.'  '.basename($outPath).PHP_EOL);
                $log->info('db backup created', ['path' => $outPath, 'sha256' => $sha]);
                $this->info('Backup created: '.$outPath);
            } catch (\Throwable $e) {
                $log->error('db backup failed', ['error' => $e->getMessage()]);
                $this->error('Backup failed. Aborting (see schema-sync.log).');
                return self::FAILURE;
            }
        }
    }

    try {
        if (Schema::hasTable('migrations') && Schema::hasTable('store_settings')) {
            $migrationName = '2026_05_14_120000_create_store_settings_table';
            $exists = DB::table('migrations')->where('migration', $migrationName)->exists();
            if (! $exists) {
                $batch = (int) (DB::table('migrations')->max('batch') ?? 0);
                $batch = max(1, $batch);
                DB::table('migrations')->insert([
                    'migration' => $migrationName,
                    'batch' => $batch,
                ]);
                $log->info('reconciled missing migration row', ['migration' => $migrationName, 'batch' => $batch]);
            }

            $fkExists = DB::table('information_schema.table_constraints')
                ->where('table_schema', DB::connection()->getDatabaseName())
                ->where('table_name', 'store_settings')
                ->where('constraint_type', 'FOREIGN KEY')
                ->exists();

            if (! $fkExists && Schema::hasColumn('store_settings', 'store_id') && Schema::hasTable('stores')) {
                $orphans = (int) (DB::selectOne(
                    'SELECT COUNT(*) AS c
                     FROM store_settings ss
                     LEFT JOIN stores s ON s.id = ss.store_id
                     WHERE ss.store_id IS NOT NULL AND s.id IS NULL'
                )->c ?? 0);

                if ($orphans === 0) {
                    DB::statement('ALTER TABLE `store_settings` ADD CONSTRAINT `store_settings_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE CASCADE');
                    $log->info('added missing store_settings FK');
                } else {
                    $log->warning('skipped adding store_settings FK due to orphan rows', ['orphans' => $orphans]);
                }
            }
        }
    } catch (\Throwable $e) {
        $log->warning('pre-migrate reconciliation failed', ['error' => $e->getMessage()]);
    }

    $pendingBefore = null;
    try {
        $before = Artisan::call('migrate:status');
        $pendingBefore = str_contains(Artisan::output(), 'Pending') ? 'yes' : 'no';
        if ($before !== self::SUCCESS) {
            $pendingBefore = null;
        }
    } catch (\Throwable) {
        $pendingBefore = null;
    }

    $code = Artisan::call('migrate', ['--force' => true]);
    $out = Artisan::output();
    $log->info('migrate finished', ['exit' => $code, 'output' => $out, 'pending_before' => $pendingBefore]);

    if ($code !== self::SUCCESS) {
        $this->error('Migration failed. See storage/logs/schema-sync.log');
        return self::FAILURE;
    }

    $status = Artisan::call('migrate:status');
    $statusOut = Artisan::output();
    $log->info('migrate:status', ['exit' => $status, 'output' => $statusOut]);
    if ($status !== self::SUCCESS || str_contains($statusOut, 'Pending')) {
        $this->error('Migrations did not fully apply (pending remains). See schema-sync.log');
        return self::FAILURE;
    }

    try {
        DB::selectOne('select 1 as ok');
    } catch (\Throwable $e) {
        $log->error('post-migrate query failed', ['error' => $e->getMessage()]);
        $this->error('DB not operational after sync. See schema-sync.log');
        return self::FAILURE;
    }

    if ($this->option('verify')) {
        $dir = storage_path('app/schema-sync-tests');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $name = Carbon::now()->format('Y_m_d_His').'_schema_sync_test_add_column_to_users';
        $file = $dir.'/'.$name.'.php';
        $rel = Str::after($file, base_path().DIRECTORY_SEPARATOR);

        $payload = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        Schema::table('users', function (Blueprint \$table) {\n            if (! Schema::hasColumn('users', 'schema_sync_test_col')) {\n                \$table->string('schema_sync_test_col', 64)->nullable();\n            }\n        });\n    }\n\n    public function down(): void\n    {\n        Schema::table('users', function (Blueprint \$table) {\n            if (Schema::hasColumn('users', 'schema_sync_test_col')) {\n                \$table->dropColumn('schema_sync_test_col');\n            }\n        });\n    }\n};\n";
        file_put_contents($file, $payload);

        $log->info('verify migration created', ['file' => $file]);

        $m1 = Artisan::call('migrate', ['--force' => true, '--path' => $rel]);
        $log->info('verify migrate', ['exit' => $m1, 'output' => Artisan::output()]);
        if ($m1 !== self::SUCCESS || ! Schema::hasColumn('users', 'schema_sync_test_col')) {
            $this->error('Verify step failed: column was not added.');
            return self::FAILURE;
        }

        $r1 = Artisan::call('migrate:rollback', ['--force' => true, '--path' => $rel, '--step' => 1]);
        $log->info('verify rollback', ['exit' => $r1, 'output' => Artisan::output()]);
        if ($r1 !== self::SUCCESS || Schema::hasColumn('users', 'schema_sync_test_col')) {
            $this->error('Verify step failed: rollback did not remove column.');
            return self::FAILURE;
        }

        @unlink($file);
        $this->info('Verify OK (column add + rollback succeeded).');
    }

    $this->info('Schema sync OK.');
    return self::SUCCESS;
})->purpose('Apply pending migrations safely (backup + logging + validation)');

Artisan::command('ngrok:sync-url {--port= : Local port forwarded by ngrok (e.g. 8000)} {--write-env : Persist URL into .env (APP_URL, WHATSAPP_MEDIA_BASE_URL)}', function () {
    if (! App::environment('local') || ! (bool) config('app.debug')) {
        $this->error('ngrok:sync-url is only available in local debug environments.');
        return self::FAILURE;
    }

    $port = (int) ($this->option('port') ?? 0);
    $tunnels = Http::timeout(2)->get('http://127.0.0.1:4040/api/tunnels')->json('tunnels') ?? [];
    if (! is_array($tunnels) || $tunnels === []) {
        $this->error('No ngrok tunnels found. Start ngrok and try again.');
        return self::FAILURE;
    }

    $pick = function (array $tunnels, int $port) {
        $https = array_values(array_filter($tunnels, fn ($t) => is_array($t) && ($t['proto'] ?? null) === 'https'));
        $pool = $https !== [] ? $https : $tunnels;
        if ($port > 0) {
            foreach ($pool as $t) {
                $addr = (string) (($t['config']['addr'] ?? '') ?: ($t['config']['addr'] ?? ''));
                if ($addr !== '' && str_ends_with($addr, ':'.$port)) {
                    return $t;
                }
            }
        }
        return $pool[0] ?? null;
    };

    $chosen = $pick($tunnels, $port);
    $publicUrl = is_array($chosen) ? trim((string) ($chosen['public_url'] ?? '')) : '';
    if ($publicUrl === '') {
        $this->error('Unable to read public_url from ngrok API.');
        return self::FAILURE;
    }

    $publicUrl = rtrim($publicUrl, '/');
    StoreCache::forever('whatsapp_public_base_url', $publicUrl);

    $this->info('Saved public base URL: '.$publicUrl);

    $health = Http::timeout(4)->get(Str::finish($publicUrl, '/').'up');
    if (! $health->ok()) {
        $this->warn('Ngrok URL saved, but /up health check did not return 200.');
    }

    if ($this->option('write-env')) {
        $envPath = base_path('.env');
        if (is_file($envPath)) {
            $raw = (string) file_get_contents($envPath);
            $set = function (string $raw, string $key, string $value): string {
                $line = $key.'='.$value;
                if (preg_match('/^'.preg_quote($key, '/').'\s*=/m', $raw)) {
                    return preg_replace('/^'.preg_quote($key, '/').'\s*=.*$/m', $line, $raw) ?? $raw;
                }
                return rtrim($raw).PHP_EOL.$line.PHP_EOL;
            };
            $raw = $set($raw, 'APP_URL', $publicUrl);
            $raw = $set($raw, 'WHATSAPP_MEDIA_BASE_URL', $publicUrl);
            $raw = $set($raw, 'INVOICE_PLAYWRIGHT_BASE_URL', $publicUrl);
            $raw = $set($raw, 'TRUSTED_PROXIES', '*');
            file_put_contents($envPath, $raw);
            $this->info('Updated .env (APP_URL, WHATSAPP_MEDIA_BASE_URL, INVOICE_PLAYWRIGHT_BASE_URL, TRUSTED_PROXIES).');
        }
    }

    return self::SUCCESS;
})->purpose('Sync ngrok public URL into settings (and optionally .env)');

Artisan::command('ngrok:watch {--port= : Local port forwarded by ngrok (e.g. 8000)} {--interval=10 : Poll interval in seconds} {--write-env : Persist URL into .env (APP_URL, WHATSAPP_MEDIA_BASE_URL)}', function () {
    if (! App::environment('local') || ! (bool) config('app.debug')) {
        $this->error('ngrok:watch is only available in local debug environments.');
        return self::FAILURE;
    }

    $port = (int) ($this->option('port') ?? 0);
    $interval = max(3, (int) ($this->option('interval') ?? 10));
    $last = null;

    $this->info('Watching ngrok tunnels via http://127.0.0.1:4040 (Ctrl+C to stop).');

    while (true) {
        try {
            $tunnels = Http::timeout(2)->get('http://127.0.0.1:4040/api/tunnels')->json('tunnels') ?? [];
        } catch (\Throwable $e) {
            $this->warn('Unable to reach ngrok API (4040).');
            sleep($interval);
            continue;
        }

        if (! is_array($tunnels) || $tunnels === []) {
            $this->warn('No ngrok tunnels found.');
            sleep($interval);
            continue;
        }

        $https = array_values(array_filter($tunnels, fn ($t) => is_array($t) && ($t['proto'] ?? null) === 'https'));
        $pool = $https !== [] ? $https : $tunnels;
        $chosen = null;
        if ($port > 0) {
            foreach ($pool as $t) {
                $addr = (string) (($t['config']['addr'] ?? '') ?: ($t['config']['addr'] ?? ''));
                if ($addr !== '' && str_ends_with($addr, ':'.$port)) {
                    $chosen = $t;
                    break;
                }
            }
        }
        if (! $chosen) {
            $chosen = $pool[0] ?? null;
        }

        $publicUrl = is_array($chosen) ? rtrim(trim((string) ($chosen['public_url'] ?? '')), '/') : '';
        if ($publicUrl !== '' && $publicUrl !== $last) {
            StoreCache::forever('whatsapp_public_base_url', $publicUrl);
            $this->info('Updated public base URL: '.$publicUrl);

            if ($this->option('write-env')) {
                Artisan::call('ngrok:sync-url', array_filter([
                    '--port' => $port > 0 ? (string) $port : null,
                    '--write-env' => true,
                ]));
            }

            $last = $publicUrl;
        }

        sleep($interval);
    }
})->purpose('Continuously sync ngrok URL if it changes');

Artisan::command('security:audit-access-log {file : Path to a raw access log file (plain text)} {--top=20 : Top N IPs and paths} {--allow-ip= : Comma-separated allowlist IPs (treated as low risk)}', function () {
    $file = (string) $this->argument('file');
    if ($file === '' || ! is_file($file)) {
        $this->error('Log file not found: '.$file);
        return self::FAILURE;
    }

    $top = max(5, min(200, (int) $this->option('top')));
    $allowRaw = trim((string) ($this->option('allow-ip') ?? ''));
    $allow = $allowRaw !== '' ? array_values(array_filter(array_map('trim', explode(',', $allowRaw)))) : [];
    $allow = array_values(array_unique($allow));

    $ipCount = [];
    $pathCount = [];
    $statusByIp = [];
    $anomalies = [];
    $lineNo = 0;

    $probePath = '#/(?:\.env|\.git|vendor/phpunit|phpinfo\.php|server-status|wp-login\.php|wp-admin)#i';
    $traversal = '#\.\./|\.\.\\\\|%2e%2e#i';

    $fh = fopen($file, 'rb');
    if ($fh === false) {
        $this->error('Unable to open log file.');
        return self::FAILURE;
    }

    while (($line = fgets($fh)) !== false) {
        $lineNo++;
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (! preg_match('/^(?<ip>\\S+) \\S+ \\S+ \\[(?<ts>[^\\]]+)\\] "(?<method>[A-Z]+) (?<path>[^"\\s]+)(?: [^"]+)?" (?<status>\\d{3}) (?<bytes>\\S+) "(?<ref>[^"]*)" "(?<ua>[^"]*)"/', $line, $m)) {
            continue;
        }

        $ip = (string) ($m['ip'] ?? '');
        $method = (string) ($m['method'] ?? '');
        $rawPath = (string) ($m['path'] ?? '');
        $status = (int) ($m['status'] ?? 0);

        $pathOnly = $rawPath;
        $qpos = strpos($rawPath, '?');
        if ($qpos !== false) {
            $pathOnly = substr($rawPath, 0, $qpos);
        }
        if ($pathOnly === '') {
            $pathOnly = '/';
        }

        $ipCount[$ip] = ($ipCount[$ip] ?? 0) + 1;
        $pathCount[$pathOnly] = ($pathCount[$pathOnly] ?? 0) + 1;
        $statusByIp[$ip][$status] = ($statusByIp[$ip][$status] ?? 0) + 1;

        $isAllowed = in_array($ip, $allow, true);
        $sev = $isAllowed ? 'low' : 'high';

        if (! in_array($method, ['GET', 'POST', 'HEAD'], true)) {
            $anomalies[] = [$sev, $ip, $method, $pathOnly, $status, 'unexpected_method', $lineNo];
        }
        if (preg_match($probePath, $pathOnly)) {
            $anomalies[] = [$sev, $ip, $method, $pathOnly, $status, 'known_probe_path', $lineNo];
        }
        if (preg_match($traversal, $rawPath)) {
            $anomalies[] = [$sev, $ip, $method, $pathOnly, $status, 'path_traversal_probe', $lineNo];
        }
    }
    fclose($fh);

    arsort($ipCount);
    arsort($pathCount);

    $this->info('Security access-log audit');
    $this->line('File: '.$file);
    $this->line('Lines parsed: '.number_format($lineNo));
    $this->newLine();

    $this->info('Top IPs');
    $i = 0;
    foreach ($ipCount as $ip => $c) {
        $i++;
        if ($i > $top) {
            break;
        }
        $codes = $statusByIp[$ip] ?? [];
        krsort($codes);
        $hot = [];
        foreach ($codes as $code => $cc) {
            if (in_array((int) $code, [200, 301, 302], true)) {
                continue;
            }
            $hot[] = $code.':'.$cc;
            if (count($hot) >= 4) {
                break;
            }
        }
        $this->line(sprintf('%-18s %8s req%s%s', $ip, number_format($c), $c === 1 ? '' : 's', $hot !== [] ? ('  ['.implode(' ', $hot).']') : ''));
    }
    $this->newLine();

    $this->info('Top Paths');
    $i = 0;
    foreach ($pathCount as $p => $c) {
        $i++;
        if ($i > $top) {
            break;
        }
        $this->line(sprintf('%-60s %8s', $p, number_format($c)));
    }
    $this->newLine();

    if ($anomalies !== []) {
        $this->info('Anomalies (first 100)');
        $max = min(100, count($anomalies));
        for ($k = 0; $k < $max; $k++) {
            [$sev, $ip, $method, $path, $status, $kind, $ln] = $anomalies[$k];
            $this->line(sprintf('[%s] %s %s %s %s (%s) line=%d', strtoupper($sev), $ip, $method, $path, $status, $kind, $ln));
        }
        if (count($anomalies) > 100) {
            $this->line('... truncated ('.count($anomalies).' total anomalies)');
        }
    } else {
        $this->info('No anomalies detected by heuristic rules.');
    }

    return self::SUCCESS;
})->purpose('Analyze exported cPanel raw access logs for suspicious patterns');

Artisan::command('e2e:cleanup {--yes : Skip confirmation}', function () {
    if (! App::environment('local') && ! App::environment('testing')) {
        $this->error('e2e:cleanup is only available in local/testing environments.');
        return self::FAILURE;
    }

    if (! $this->option('yes')) {
        if (! $this->confirm('Delete Playwright E2E rentals and outfits from the database?')) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }
    }

    $rentalPrefixes = [
        'Overdue Customer ',
        'Return Customer ',
        'Customer ',
        'Customer Updated ',
        'WA Customer ',
    ];
    $outfitPrefixes = [
        'E2E-',
        'WA-E2E-',
    ];

    $counts = DB::transaction(function () use ($rentalPrefixes, $outfitPrefixes) {
        $rentalQuery = DB::table('rentals');
        foreach ($rentalPrefixes as $prefix) {
            $rentalQuery->orWhere('customer_name', 'like', $prefix.'%');
        }
        $rentalIds = $rentalQuery->pluck('id')->all();
        $rentalsDeleted = 0;
        if (! empty($rentalIds)) {
            $rentalsDeleted = DB::table('rentals')->whereIn('id', $rentalIds)->delete();
        }

        $outfitQuery = DB::table('outfits');
        foreach ($outfitPrefixes as $prefix) {
            $outfitQuery->orWhere('code', 'like', $prefix.'%');
        }
        $outfitIds = $outfitQuery->pluck('id')->all();
        $outfitsDeleted = 0;
        if (! empty($outfitIds)) {
            $outfitsDeleted = DB::table('outfits')->whereIn('id', $outfitIds)->delete();
        }

        return [$rentalsDeleted, $outfitsDeleted];
    });

    $this->info('Deleted rentals: '.((int) ($counts[0] ?? 0)));
    $this->info('Deleted outfits: '.((int) ($counts[1] ?? 0)));
    return self::SUCCESS;
})->purpose('Delete Playwright E2E rentals/outfits from the database');

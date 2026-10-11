<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class DbBackup extends Command
{
    protected $signature = 'db:backup {--keep=30 : Number of days to keep backups}';
    protected $description = 'Backup database to db-backup folder';

    public function handle()
    {
        $driver = DB::connection()->getDriverName();
        $backupDir = base_path('db-backup');

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_H-i-s');
        $filename = "backup_{$timestamp}.sql";
        $path = "{$backupDir}/{$filename}";

        if ($driver === 'mysql') {
            $this->backupMysql($path);
        } elseif ($driver === 'sqlite') {
            $this->backupSqlite($path);
        } else {
            $this->error("Unsupported driver: {$driver}");
            return 1;
        }

        if (!file_exists($path)) {
            $this->error('Backup failed');
            return 1;
        }

        $this->info("Backup created: {$filename}");
        $this->cleanOld($backupDir, (int) $this->option('keep'));
        $this->info("Done. Backups stored in: {$backupDir}");

        return 0;
    }

    protected function backupMysql(string $path): void
    {
        $db = config('database.connections.mysql.database');
        $user = config('database.connections.mysql.username');
        $pass = config('database.connections.mysql.password');
        $host = config('database.connections.mysql.host');

        $esc = function ($v) {
            return "'" . addslashes($v) . "'";
        };

        $dump = "-- Rental Suite Backup - " . now() . "\n";
        $dump .= "-- Host: {$host}  DB: {$db}\n\n";
        $dump .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

        try {
            $pdo = DB::connection()->getPdo();
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $create = $row['Create Table'] ?? '';

                $dump .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $dump .= "{$create};\n\n";

                $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) continue;

                $columns = array_keys($rows[0]);
                $cols = '`' . implode('`, `', $columns) . '`';

                $dump .= "INSERT INTO `{$table}` ({$cols}) VALUES\n";
                $vals = [];
                foreach ($rows as $row) {
                    $escaped = [];
                    foreach ($row as $val) {
                        $escaped[] = $val === null ? 'NULL' : $esc($val);
                    }
                    $vals[] = '(' . implode(', ', $escaped) . ')';
                }
                $dump .= implode(",\n", $vals) . ";\n\n";
            }

            $dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";
            file_put_contents($path, $dump);
        } catch (\Exception $e) {
            $this->error("MySQL backup failed: " . $e->getMessage());
        }
    }

    protected function backupSqlite(string $path): void
    {
        $dbPath = config('database.connections.sqlite.database');
        if (!file_exists($dbPath)) {
            $this->error("SQLite database not found: {$dbPath}");
            return;
        }
        copy($dbPath, $path);
        $this->info("SQLite database copied to: {$path}");
    }

    protected function cleanOld(string $dir, int $keepDays): void
    {
        $files = glob("{$dir}/backup_*.sql");
        $cutoff = now()->subDays($keepDays);

        foreach ($files as $file) {
            $mtime = \Carbon\Carbon::createFromTimestamp(filemtime($file));
            if ($mtime->lt($cutoff)) {
                unlink($file);
                $this->info("Deleted old backup: " . basename($file));
            }
        }
    }
}

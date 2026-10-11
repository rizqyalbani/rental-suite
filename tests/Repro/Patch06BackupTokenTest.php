<?php

namespace Tests\Repro;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Control test for the OPTIONAL patch 06-backup-endpoint-hardening (finding C6-04, /backup?token=).
 * The patch only changes the comparison to hash_equals() and forces a string token, so nothing observable changes:
 * this test PASSES before AND after. It documents that the existing cron call (GET /backup?token=...) keeps working,
 * and that odd tokens still end in 401, not in a 500.
 * (db:backup is mocked: the real command would write a dump into the repo's db-backup/ folder.)
 *   php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Repro/Patch06BackupTokenTest.php
 */
class Patch06BackupTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['BACKUP_SECRET'] = $_ENV['BACKUP_SECRET'] = 'repro-backup-secret';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['BACKUP_SECRET'], $_ENV['BACKUP_SECRET']);
        parent::tearDown();
    }

    public function test_missing_wrong_empty_and_array_tokens_are_refused_with_401(): void
    {
        Artisan::shouldReceive('call')->never();

        $this->get('/backup')->assertStatus(401);
        $this->get('/backup?token=')->assertStatus(401);
        $this->get('/backup?token=wrong')->assertStatus(401);
        $this->get('/backup?token=repro-backup-secre')->assertStatus(401);
        $this->get('/backup?token[]=repro-backup-secret')->assertStatus(401);
    }

    public function test_correct_token_still_runs_the_backup_command(): void
    {
        Artisan::shouldReceive('call')->once()->with('db:backup')->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('Backup created: test');

        $this->get('/backup?token=repro-backup-secret')->assertOk()->assertSee('Backup created: test');
    }

    public function test_without_a_configured_secret_the_endpoint_is_closed(): void
    {
        unset($_SERVER['BACKUP_SECRET'], $_ENV['BACKUP_SECRET']);
        Artisan::shouldReceive('call')->never();

        $this->get('/backup?token=anything')->assertStatus(401);
        $this->get('/backup')->assertStatus(401);
    }
}

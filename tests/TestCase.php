<?php

namespace Tests;

use App\Models\ShiftSession;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // StoreContext is static and survives between tests in one phpunit process; a stale
        // store id from an earlier test makes BelongsToStore models hit FOREIGN KEY errors.
        \App\Support\StoreContext::set(null, false);
        // The local .env carries a live Fonnte token: a test must never reach a real
        // customer. Any outbound HTTP a test did not explicitly fake throws instead.
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        config()->set('services.fonnte.token', null);
    }

    protected function openShiftAs(User $user): ShiftSession
    {
        return ShiftSession::create([
            'opened_by_user_id' => $user->id,
            'opened_at' => now(),
            'period' => ShiftSession::PERIOD_MORNING,
        ]);
    }
}

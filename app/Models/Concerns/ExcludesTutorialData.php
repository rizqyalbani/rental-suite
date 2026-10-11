<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Records created by the "How approvals work" onboarding tour (real Create
 * Rent / Submit for Approval actions, tagged is_tutorial=true so the tour can
 * teach the actual UI without a separate sandbox) — excluded from every normal
 * query by default so they never show up mixed in with real business data.
 * Use withoutGlobalScope('tutorial_scope') for tooling that specifically needs
 * to see or clean up tutorial records.
 */
trait ExcludesTutorialData
{
    public static function bootExcludesTutorialData(): void
    {
        static::addGlobalScope('tutorial_scope', function (Builder $query): void {
            $query->where($query->qualifyColumn('is_tutorial'), false);
        });
    }

    /**
     * The scope is about keeping tutorial records out of LISTINGS, not about
     * access control — a direct lookup by id (route-model binding: the tour's
     * own "Cancel & continue" cancel button, an edit/show link) is only reachable
     * if the caller already has the id, which for a tutorial record only the
     * tour's own JS does (it's never shown in any real list). Without this
     * override, self-cancelling a tutorial approval 404s because the scope hides
     * it from the binding lookup too.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->resolveRouteBindingQuery($this, $value, $field)
            ->withoutGlobalScope('tutorial_scope')
            ->first();
    }
}

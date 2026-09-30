<?php

namespace App\Livewire\Admin;

use App\Domain\Operations\Queries\StaleActionJobsQuery;
use App\Models\Job;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Session-wide stale-action modal.
 *
 * Previously lived inside App\Livewire\Admin\Operations\OperationsDashboard
 * as an in-page modal on the Ops dashboard.  When the 2026-09-30
 * staff-request nav cut hid that dashboard from ops / dispatch /
 * super_admin, the gate stopped reaching the people it was designed
 * to enforce -- so it got split out into a standalone Livewire
 * component mounted once by the app layout for every internal-tier
 * user.  The same modal now blocks whichever page they landed on
 * (Orders, Planning, Dispatch, wherever) until they've either
 * transitioned their stale rows or written a "wait longer" reason
 * against each one.
 *
 * Route suppression: on `admin.orders.show` the gate does NOT render
 * -- deep-links from the modal ("Open to cancel / deliver →") land
 * there, and blocking that page would make the modal impossible to
 * satisfy.  A real status move on the order clears every snooze
 * field via the Job::booted() hook, so the row drops off the list
 * on the next page load elsewhere.
 *
 * Every other route re-arms the gate on load, exactly as the old
 * ops-dashboard version did.
 */
class StaleActionGate extends Component
{
    /**
     * Session key used to suppress the once-per-session "others only"
     * heads-up after Close.  Owned rows always re-open the modal.
     */
    private const SESSION_STALE_GATE_ACK = 'ops_stale_gate_acked_at';

    public bool $showStaleGate = false;

    /**
     * Per-row comment inputs, keyed by job id.  Each owned row needs
     * its own textarea so ops can queue up several snoozes on one
     * visit without the comment field jumping between rows.
     *
     * @var array<int,string>
     */
    public array $staleComments = [];

    public function mount(): void
    {
        // Only internal-tier users see the gate.  Includes anon,
        // customer, driver -> return early with $showStaleGate = false.
        $actor = auth()->user();
        if (! $actor || ! method_exists($actor, 'isInternal') || ! $actor->isInternal()) {
            return;
        }

        // Deep-links out of the modal land on the order detail page
        // so ops can Cancel / Deliver / transitionTo() the stale row.
        // Blocking that page would make the modal impossible to
        // satisfy, so the gate suppresses itself there and re-arms
        // on every other route the user visits.
        if (request()->routeIs('admin.orders.show')) {
            return;
        }

        $this->showStaleGate = $this->shouldShowStaleGate(
            (new StaleActionJobsQuery())->forUser($actor)
        );
    }

    /**
     * "Wait longer" on a single stale row.  Requires a non-empty
     * comment on that row -- an empty snooze would strip the whole
     * point of the gate, which is a written record of WHY ops is
     * happy to sit on this for another week.
     *
     * The actual write goes through Job::snoozeStaleAction() so the
     * audit trail + timeline note fire from one place.
     */
    public function snoozeStale(int $jobId): void
    {
        $actor = auth()->user();
        if (! $actor) {
            return;
        }

        $comment = trim((string) ($this->staleComments[$jobId] ?? ''));
        if ($comment === '') {
            $this->addError('staleComment.' . $jobId, 'Add a short reason before waiting longer.');
            return;
        }

        $job = Job::query()
            ->whereKey($jobId)
            ->where('created_by_user_id', $actor->id) // creators only
            ->first();

        if (! $job) {
            // Silently drop: either the job was moved / cancelled by
            // someone else while the modal was open, or the actor is
            // not the creator.  Either way, the next render pulls a
            // fresh list and the row disappears.
            unset($this->staleComments[$jobId]);
            return;
        }

        $job->snoozeStaleAction($actor, $comment);
        unset($this->staleComments[$jobId]);
        $this->resetErrorBag('staleComment.' . $jobId);
    }

    /**
     * Close the modal.  Only permitted when the actor has no OWN
     * stale rows still in play -- otherwise we drop back into the
     * modal on next render anyway, so the button pretends not to
     * exist.  The server-side guard here also stops a crafted
     * request from bypassing the disabled attribute in the client.
     *
     * A successful Close also marks the session so an others-only
     * list does not pop again on every page load.
     */
    public function dismissStaleGate(): void
    {
        $actor = auth()->user();
        if (! $actor) {
            return;
        }

        $data = (new StaleActionJobsQuery())->forUser($actor);
        if ($data['owned']->isNotEmpty()) {
            return;
        }

        session()->put(self::SESSION_STALE_GATE_ACK, now()->toIso8601String());
        $this->showStaleGate = false;
    }

    /**
     * Owned stale rows always force the gate.  Others-only is a
     * once-per-session heads-up -- after Close (or if already
     * acknowledged this login), stay out of the way.
     *
     * @param  array{owned: Collection, others: Collection, total: int, worst_days: int}  $data
     */
    private function shouldShowStaleGate(array $data): bool
    {
        if ($data['owned']->isNotEmpty()) {
            return true;
        }

        if ($data['others']->isEmpty()) {
            return false;
        }

        return ! session()->has(self::SESSION_STALE_GATE_ACK);
    }

    public function render()
    {
        return view('livewire.admin.stale-action-gate', [
            'stale' => $this->currentStaleData(),
        ]);
    }

    /**
     * Pulled into its own method so the render + close-guard paths
     * cannot disagree on what "the current stale set" means.
     *
     * @return array{owned: Collection, others: Collection, total: int, worst_days: int}
     */
    private function currentStaleData(): array
    {
        $actor = auth()->user();
        if (! $actor || ! method_exists($actor, 'isInternal') || ! $actor->isInternal()) {
            return [
                'owned'      => collect(),
                'others'     => collect(),
                'total'      => 0,
                'worst_days' => 0,
            ];
        }

        return (new StaleActionJobsQuery())->forUser($actor);
    }
}

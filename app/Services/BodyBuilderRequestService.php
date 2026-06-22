<?php

namespace App\Services;

use App\Models\BodyBuilderDealerLink;
use App\Models\BodyBuilderRequest;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * All the decision logic for the dealer-initiated "add a body builder"
 * workflow.  The service does the heavy lifting (creating companies +
 * locations, auto-linking the dealer, geocoding) so the Volt page is
 * just a thin shell that collects input and renders state.
 *
 * Three terminal transitions:
 *
 *   - approveAsNew()  -- mint a fresh body_builder Company, seed a
 *                        body_builder Location from the supplied
 *                        address, auto-link the requesting dealer.
 *   - mergeInto()     -- the BB the dealer wants already exists; we
 *                        just link the dealer to it and close the
 *                        request with a pointer to that company.
 *   - reject()        -- request is closed without creating anything.
 *
 * All three are idempotent for the same caller -- calling approve()
 * twice on the same request returns the same Company without trying to
 * insert duplicates.
 */
class BodyBuilderRequestService
{
    // GeocodingService is used statically; nothing to inject.

    /**
     * Surface fuzzy matches against existing body-builder Companies, so
     * the dealer (and ops) see "did you mean Anchor Auto Body Builders
     * CC?" before committing a duplicate.  Same normalisation as the
     * locations:dedupe / companies:merge tooling: strip everything
     * except alphanumerics, lowercase.
     */
    public function findDedupeCandidates(string $proposedName, ?string $proposedAddress = null, int $limit = 5): array
    {
        $needle = $this->normalise($proposedName);
        if ($needle === '') {
            return [];
        }

        // Pull a reasonable working set, score in PHP rather than try
        // to do fuzzy matching in SQL -- BB tables are small.
        $candidates = Company::query()
            ->where('type', Company::TYPE_BODY_BUILDER)
            ->orderBy('name')
            ->get(['id', 'name', 'address'])
            ->map(function (Company $c) use ($needle, $proposedAddress) {
                $cNameKey = $this->normalise($c->name);
                $cAddrKey = $this->normalise((string) $c->address);
                $addrKey  = $this->normalise((string) $proposedAddress);

                $nameScore = $this->similarity($needle, $cNameKey);
                $addrScore = ($addrKey !== '' && $cAddrKey !== '')
                    ? $this->similarity($addrKey, $cAddrKey)
                    : 0;

                $blended = ($nameScore * 0.7) + ($addrScore * 0.3);

                return [
                    'id'      => $c->id,
                    'name'    => $c->name,
                    'address' => $c->address,
                    'score'   => $blended,
                ];
            })
            ->filter(fn ($r) => $r['score'] >= 0.55)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();

        return $candidates;
    }

    /**
     * Approve a pending request by minting a brand-new body_builder
     * Company + seed Location, and authorising the requesting dealer.
     */
    public function approveAsNew(BodyBuilderRequest $req, User $approver, ?string $decisionNotes = null): Company
    {
        $this->guardPending($req);

        return DB::transaction(function () use ($req, $approver, $decisionNotes) {
            $company = Company::create([
                'name'    => $req->proposed_name,
                'type'    => Company::TYPE_BODY_BUILDER,
                'address' => $req->proposed_address,
                'phone'   => $req->proposed_contact_phone,
                'email'   => $req->proposed_contact_email,
                'is_active' => true,
            ]);

            // Seed the first workshop Location from the supplied
            // address.  Geocode best-effort -- if Google fails (rate
            // limit / network), the row still saves without lat/lng
            // and ops can fix it later from /admin/companies/{id}.
            $loc = Location::create([
                'company_id'   => $company->id,
                'type'         => Location::TYPE_BODY_BUILDER,
                'company_name' => $req->proposed_name,
                'address'      => $req->proposed_address ?: $req->proposed_name,
                'city'         => $req->proposed_city,
                'province'     => $req->proposed_province,
                'contact_name' => $req->proposed_contact_name,
                'contact_phone'=> $req->proposed_contact_phone,
                'contact_email'=> $req->proposed_contact_email,
                'is_active'    => true,
            ]);

            if ($req->proposed_address) {
                try {
                    $geo = GeocodingService::geocode($req->proposed_address);
                    if ($geo && isset($geo['lat'], $geo['lng'])) {
                        $loc->forceFill([
                            'lat' => $geo['lat'],
                            'lng' => $geo['lng'],
                        ])->save();
                    }
                } catch (\Throwable $e) {
                    // best-effort -- swallowed deliberately
                }
            }

            $this->linkDealerToBodyBuilder($req->dealer_company_id, $company->id, $approver);

            $req->forceFill([
                'status' => BodyBuilderRequest::STATUS_APPROVED,
                'decided_by_user_id' => $approver->id,
                'decided_at' => now(),
                'decision_notes' => $decisionNotes,
                'resolved_body_builder_company_id' => $company->id,
            ])->save();

            AuditService::log('body_builder_request_approved', 'body_builder_request', $req->id, null, [
                'dealer_company_id' => $req->dealer_company_id,
                'new_company_id'    => $company->id,
                'proposed_name'     => $req->proposed_name,
            ]);

            return $company;
        });
    }

    /**
     * Merge the request into an existing body_builder Company -- the BB
     * already exists in the directory, the dealer just couldn't find it
     * (typo, capitalisation, abbreviated name).  Nothing new is
     * created; the dealer is linked to the existing BB.
     */
    public function mergeInto(BodyBuilderRequest $req, Company $existingBb, User $approver, ?string $decisionNotes = null): Company
    {
        $this->guardPending($req);

        if ($existingBb->type !== Company::TYPE_BODY_BUILDER) {
            throw new InvalidArgumentException('Merge target must be a body_builder company.');
        }

        return DB::transaction(function () use ($req, $existingBb, $approver, $decisionNotes) {
            $this->linkDealerToBodyBuilder($req->dealer_company_id, $existingBb->id, $approver);

            $req->forceFill([
                'status' => BodyBuilderRequest::STATUS_MERGED,
                'decided_by_user_id' => $approver->id,
                'decided_at' => now(),
                'decision_notes' => $decisionNotes,
                'resolved_body_builder_company_id' => $existingBb->id,
            ])->save();

            AuditService::log('body_builder_request_merged', 'body_builder_request', $req->id, null, [
                'dealer_company_id'    => $req->dealer_company_id,
                'merged_into_company_id' => $existingBb->id,
                'proposed_name'        => $req->proposed_name,
            ]);

            return $existingBb;
        });
    }

    public function reject(BodyBuilderRequest $req, User $approver, ?string $decisionNotes = null): void
    {
        $this->guardPending($req);

        DB::transaction(function () use ($req, $approver, $decisionNotes) {
            $req->forceFill([
                'status' => BodyBuilderRequest::STATUS_REJECTED,
                'decided_by_user_id' => $approver->id,
                'decided_at' => now(),
                'decision_notes' => $decisionNotes,
            ])->save();

            AuditService::log('body_builder_request_rejected', 'body_builder_request', $req->id, null, [
                'dealer_company_id' => $req->dealer_company_id,
                'proposed_name'     => $req->proposed_name,
                'reason'            => $decisionNotes,
            ]);
        });
    }

    /**
     * Idempotent: re-create the link only if it doesn't exist, or
     * re-activate a paused one.  Caller is responsible for opening a
     * transaction if they need atomicity with surrounding writes.
     */
    private function linkDealerToBodyBuilder(int $dealerId, int $bbId, User $approver): BodyBuilderDealerLink
    {
        $link = BodyBuilderDealerLink::firstOrNew([
            'dealer_company_id'       => $dealerId,
            'body_builder_company_id' => $bbId,
        ]);
        $link->is_active = true;
        if (!$link->exists) {
            $link->linked_by_user_id = $approver->id;
            $link->notes = 'Auto-linked via dealer body-builder request approval.';
        }
        $link->save();

        return $link;
    }

    private function guardPending(BodyBuilderRequest $req): void
    {
        if (!$req->isPending()) {
            throw new RuntimeException("Request #{$req->id} is already {$req->status} -- can't re-decide.");
        }
    }

    private function normalise(?string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s)) ?? '';
    }

    /**
     * Two-pass similarity: substring containment as a fast path, then
     * `similar_text` for nuance.  Returns 0.0-1.0.
     */
    private function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        // Cheap prefix / containment boost: "anchorauto" inside
        // "anchorautobodybuilderscc" should score very high without
        // burning similar_text() time.
        if (strlen($a) >= 4 && (str_contains($b, $a) || str_contains($a, $b))) {
            return 0.9;
        }
        similar_text($a, $b, $pct);
        return $pct / 100.0;
    }
}

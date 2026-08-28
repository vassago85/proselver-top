<?php

namespace App\Console\Commands;

use App\Models\DriverProfile;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off backfill: normalise `driver_profiles.trade_plate` to the
 * canonical form (uppercase, alphanumeric, no spaces).  Historically
 * the column was written raw -- "TP JHB 11", "tp-jhb-11", "TP.JHB.11"
 * all coexist.  TFN's POS rejects VehicleRegistration values that
 * don't match exactly, so before we flip TFN_ENABLED=true in
 * production every driver-profile trade plate has to collapse to the
 * same shape their model accessor now emits on read.
 *
 * Idempotent -- second run is a no-op because every row is compared
 * to `DriverProfile::normalisePlate($row->trade_plate)` and skipped
 * when it already matches.
 *
 * Every write goes through AuditService::log so ops has a record of
 * what changed, in case a driver later says "you edited my plate".
 */
class DriversSanitizeTradePlates extends Command
{
    protected $signature = 'drivers:sanitize-trade-plates
        {--dry-run : Preview changes without writing to the database}
        {--limit= : Optional row cap for a quick trial run}';

    protected $description = 'Normalise driver_profiles.trade_plate to uppercase, alphanumeric-only, no spaces.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $this->line($dry
            ? '<comment>DRY RUN</comment> — no changes will be written.'
            : '<info>LIVE RUN</info> — changes will be committed and audited.');
        $this->newLine();

        $q = DriverProfile::query()->whereNotNull('trade_plate');
        if ($limit) {
            $q->orderBy('id')->limit($limit);
        }

        $tallies = [
            'scanned'   => 0,
            'unchanged' => 0,
            'normalised'=> 0,
            'nulled'    => 0,   // trade_plate was junk (only punctuation) -> nulled out
            'conflict'  => 0,   // two rows would collapse to the same non-empty plate
        ];

        // Track which normalised plates are already claimed so we can
        // refuse to create a collision.  We compare against BOTH what's
        // currently in the DB (in canonical form) AND what we're about
        // to write in this run.
        $claimedByOtherRow = DriverProfile::query()
            ->whereNotNull('trade_plate')
            ->pluck('trade_plate', 'id')
            ->map(fn ($tp) => DriverProfile::normalisePlate($tp))
            ->filter()
            ->toArray();

        $work = function () use ($q, $dry, &$tallies, &$claimedByOtherRow) {
            // Read from the RAW column so the accessor doesn't hide
            // the pre-normalisation value we're trying to survey.
            $q->select(['id', 'user_id'])
                ->addSelect(DB::raw('trade_plate AS raw_trade_plate'))
                ->each(function (DriverProfile $profile) use ($dry, &$tallies, &$claimedByOtherRow) {
                    $tallies['scanned']++;

                    $raw = $profile->getAttributes()['raw_trade_plate'] ?? null;
                    $canonical = DriverProfile::normalisePlate($raw);

                    // Case 1: already canonical -> no-op.
                    if ($raw === $canonical) {
                        $tallies['unchanged']++;
                        return;
                    }

                    // Case 2: raw is only punctuation / whitespace ->
                    // normalise() returns null.  Null the column so the
                    // driver's record no longer advertises a plate that
                    // was never valid.
                    if ($canonical === null) {
                        $tallies['nulled']++;
                        $this->line(sprintf(
                            '  [id=%d user=%d] "%s" -> NULL (was only punctuation)',
                            $profile->id,
                            $profile->user_id,
                            $raw,
                        ));
                        unset($claimedByOtherRow[$profile->id]);
                        if (!$dry) {
                            DriverProfile::where('id', $profile->id)->update(['trade_plate' => null]);
                            AuditService::log(
                                actionType: 'trade_plate_sanitised',
                                entityType: 'driver_profile',
                                entityId: $profile->id,
                                before: ['trade_plate' => $raw],
                                after:  ['trade_plate' => null],
                                reason: 'Backfill: raw value contained no alphanumerics.',
                            );
                        }
                        return;
                    }

                    // Case 3: collision.  Another row's canonical plate
                    // already claims this string -- ops must decide which
                    // is real.  Refuse to write; report and move on.
                    $collidesWith = array_search($canonical, $claimedByOtherRow, true);
                    if ($collidesWith !== false && (int) $collidesWith !== $profile->id) {
                        $tallies['conflict']++;
                        $this->warn(sprintf(
                            '  [id=%d user=%d] "%s" -> "%s" SKIPPED: would collide with driver_profile id=%d',
                            $profile->id,
                            $profile->user_id,
                            $raw,
                            $canonical,
                            $collidesWith,
                        ));
                        return;
                    }

                    // Case 4: happy path.  Rewrite in canonical form.
                    $tallies['normalised']++;
                    $this->line(sprintf(
                        '  [id=%d user=%d] "%s" -> "%s"',
                        $profile->id,
                        $profile->user_id,
                        $raw,
                        $canonical,
                    ));
                    $claimedByOtherRow[$profile->id] = $canonical;
                    if (!$dry) {
                        DriverProfile::where('id', $profile->id)->update(['trade_plate' => $canonical]);
                        AuditService::log(
                            actionType: 'trade_plate_sanitised',
                            entityType: 'driver_profile',
                            entityId: $profile->id,
                            before: ['trade_plate' => $raw],
                            after:  ['trade_plate' => $canonical],
                            reason: 'Backfill: normalise to TFN VehicleRegistration format.',
                        );
                    }
                });
        };

        if ($dry) {
            $work();
        } else {
            DB::transaction($work);
        }

        $this->newLine();
        $this->line('<info>Summary</info>');
        foreach ($tallies as $label => $count) {
            $this->line(sprintf('  %-11s %d', $label, $count));
        }

        if ($tallies['conflict'] > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '%d row%s would collide with another driver\'s canonical plate. Resolve the duplicate(s) manually, then re-run.',
                $tallies['conflict'],
                $tallies['conflict'] === 1 ? '' : 's',
            ));
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Company;
use App\Models\DealerStock;
use App\Models\VehicleModel;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Correct the make on a dealer's stock when a bulk import tagged the
 * wrong brand (e.g. an Opel dealer's whole upload set to "Isuzu").
 *
 * Two safe ways to scope it:
 *   --from=Isuzu          only rows currently tagged Isuzu
 *   --catalogue           only rows whose model is a known model of the
 *                         target make (e.g. Mokka/Corsa -> Opel)
 *
 * At least one of those is required so we never blanket-reassign a
 * dealer's entire stock by accident.  Rows already on the target make
 * are left alone.  --dry-run lists exactly what would change.
 *
 * Example:
 *   php artisan dealer-stock:reassign-make "Demo Motors" --from=Isuzu --to=Opel --dry-run
 */
class DealerStockReassignMake extends Command
{
    protected $signature = 'dealer-stock:reassign-make
        {dealer : Dealer company id or (partial) name}
        {--from= : Current brand name to reassign FROM}
        {--to=Opel : Target brand name (created if missing)}
        {--catalogue : Restrict to rows whose model matches the target make catalogue}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Reassign the make on a dealer\'s stock (e.g. correct Isuzu -> Opel after a mis-tagged import).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fromName = trim((string) $this->option('from'));
        $toName = trim((string) ($this->option('to') ?: 'Opel'));
        $catalogueOnly = (bool) $this->option('catalogue');

        if ($fromName === '' && !$catalogueOnly) {
            $this->error('Refusing to run unscoped. Pass --from=<brand> and/or --catalogue so only the right rows change.');
            return self::FAILURE;
        }

        $dealer = $this->resolveDealer((string) $this->argument('dealer'));
        if (!$dealer) {
            return self::FAILURE;
        }

        $toBrand = Brand::firstOrCreate(['name' => $toName], ['is_active' => true]);

        $fromBrand = null;
        if ($fromName !== '') {
            $fromBrand = Brand::whereRaw('LOWER(name) = ?', [strtolower($fromName)])->first();
            if (!$fromBrand) {
                $this->error("No brand named '{$fromName}' exists.");
                return self::FAILURE;
            }
        }

        $query = DealerStock::where('dealer_company_id', $dealer->id)
            ->where(function ($q) use ($toBrand) {
                $q->whereNull('brand_id')->orWhere('brand_id', '!=', $toBrand->id);
            });

        if ($fromBrand) {
            $query->where('brand_id', $fromBrand->id);
        }

        $rows = $query->orderBy('vin')->get(['id', 'vin', 'model_name', 'brand_id']);

        // Optional extra safety: only touch rows whose model is a known
        // model of the target make.
        if ($catalogueOnly) {
            $catalogue = VehicleModel::catalogue();
            $rows = $rows->filter(
                fn (DealerStock $r) => VehicleModel::brandIdForModelName($r->model_name, $catalogue) === $toBrand->id
            )->values();
        }

        if ($rows->isEmpty()) {
            $this->info("Nothing to change for {$dealer->name} (#{$dealer->id}).");
            return self::SUCCESS;
        }

        $this->info("Dealer: {$dealer->name} (#{$dealer->id})");
        $this->info("Reassigning " . $rows->count() . " row(s) → {$toBrand->name}"
            . ($fromBrand ? " (from {$fromBrand->name})" : '')
            . ($catalogueOnly ? ' [catalogue-matched]' : ''));

        $brandNames = Brand::pluck('name', 'id');
        $this->table(
            ['VIN', 'Model', 'Current make', '→'],
            $rows->map(fn (DealerStock $r) => [
                $r->vin,
                $r->model_name ?? '—',
                $r->brand_id ? ($brandNames[$r->brand_id] ?? "#{$r->brand_id}") : '(blank)',
                $toBrand->name,
            ])->all()
        );

        if ($dryRun) {
            $this->warn('Dry-run — no changes written.');
            return self::SUCCESS;
        }

        $ids = $rows->pluck('id')->all();
        DB::transaction(function () use ($ids, $toBrand, $dealer, $fromBrand, $catalogueOnly) {
            DealerStock::whereIn('id', $ids)->update(['brand_id' => $toBrand->id]);

            AuditService::log('dealer_stock_make_reassigned', 'dealer_stock', null, null, [
                'dealer_company_id' => $dealer->id,
                'from_brand' => $fromBrand?->name,
                'to_brand' => $toBrand->name,
                'catalogue_only' => $catalogueOnly,
                'count' => count($ids),
            ]);
        });

        $this->info('Done. ' . count($ids) . ' row(s) reassigned to ' . $toBrand->name . '.');

        return self::SUCCESS;
    }

    private function resolveDealer(string $needle): ?Company
    {
        $needle = trim($needle);
        if ($needle === '') {
            $this->error('Provide a dealer company id or name.');
            return null;
        }

        if (ctype_digit($needle)) {
            $company = Company::find((int) $needle);
            if (!$company) {
                $this->error("No company with id {$needle}.");
            }
            return $company;
        }

        $matches = Company::where('name', 'like', '%' . $needle . '%')->orderBy('name')->get();
        if ($matches->isEmpty()) {
            $this->error("No company matching '{$needle}'.");
            return null;
        }
        if ($matches->count() > 1) {
            $this->error("'{$needle}' matches " . $matches->count() . ' companies — be more specific or pass the id:');
            foreach ($matches as $m) {
                $this->line("  #{$m->id}  {$m->name}");
            }
            return null;
        }

        return $matches->first();
    }
}

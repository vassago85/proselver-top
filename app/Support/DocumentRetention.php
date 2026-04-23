<?php

namespace App\Support;

use App\Models\JobDocument;
use App\Models\SystemSetting;

/**
 * Document retention rules, single source of truth for the UI + any future
 * tidy-up job (`documents:prune` etc).
 *
 * Product decision 2026-04-23:
 *   - Vehicle photos (damage / pickup / delivery / dashboard / data plate /
 *     generic "photo") are kept for a FIXED 3 months. They're evidence for
 *     damage or missing-items disputes and after the claim window closes
 *     the customer is on their own. Hard-coding this keeps the retention
 *     promise visible in the UI and prevents an over-eager owner from
 *     stretching storage costs into a legal liability.
 *
 *   - Formal paperwork (collection notes, PODs, purchase orders, invoices,
 *     petty-cash slips) retention is owner-configurable. Default is 60
 *     months (5y) which matches SA Income Tax Act s29 record-keeping.
 */
class DocumentRetention
{
    public const FIXED_PHOTO_MONTHS = 3;

    public const PAPERWORK_DEFAULT_MONTHS = 60;

    public const PHOTO_CATEGORIES = [
        JobDocument::CATEGORY_DAMAGE_PHOTO,
        JobDocument::CATEGORY_PHOTO,
        JobDocument::CATEGORY_DASHBOARD,
        JobDocument::CATEGORY_DATA_PLATE,
    ];

    public const PAPERWORK_CATEGORIES = [
        JobDocument::CATEGORY_POD,
        JobDocument::CATEGORY_COLLECTION_NOTE,
        JobDocument::CATEGORY_PO,
        JobDocument::CATEGORY_INVOICE,
        JobDocument::CATEGORY_FUEL_SLIP,
        JobDocument::CATEGORY_FOOD_SLIP,
        JobDocument::CATEGORY_TOLL_SLIP,
        JobDocument::CATEGORY_PARKING_SLIP,
        JobDocument::CATEGORY_OTHER,
    ];

    public static function photoMonths(): int
    {
        return self::FIXED_PHOTO_MONTHS;
    }

    public static function paperworkMonths(): int
    {
        $value = (int) SystemSetting::get(
            'document_retention_paperwork_months',
            self::PAPERWORK_DEFAULT_MONTHS
        );
        return max(1, $value);
    }

    public static function setPaperworkMonths(int $months, ?int $userId = null): void
    {
        $months = max(1, min($months, 240));
        SystemSetting::set(
            'document_retention_paperwork_months',
            $months,
            'integer',
            'How many months to keep formal paperwork (collection notes, PODs, purchase orders, invoices, slips) before automated removal.'
        );
    }

    public static function isPhoto(string $category): bool
    {
        return in_array($category, self::PHOTO_CATEGORIES, true);
    }

    public static function isPaperwork(string $category): bool
    {
        return in_array($category, self::PAPERWORK_CATEGORIES, true);
    }

    /**
     * Short human copy for the UI notice. Rendered verbatim under the
     * documents list on dealer / oem / customer order pages.
     */
    public static function noticeText(): string
    {
        $photo = self::photoMonths();
        $paperMonths = self::paperworkMonths();
        $paperYears = round($paperMonths / 12, 1);
        $paperHuman = $paperMonths >= 12
            ? ($paperYears == floor($paperYears)
                ? ((int) $paperYears) . ' ' . ($paperYears == 1 ? 'year' : 'years')
                : $paperYears . ' years')
            : $paperMonths . ' months';

        return "Photos are kept for {$photo} months — they exist only to resolve damage or missing-item disputes raised within that window. "
            . "Collection notes, PODs, invoices and purchase orders are retained for {$paperHuman}. "
            . "Download anything you need to keep permanently now.";
    }
}

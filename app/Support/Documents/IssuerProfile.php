<?php

namespace App\Support\Documents;

use App\Models\Company;

/**
 * The identity printed on the masthead + footer of a generated
 * document (collection note, delivery note, sale delivery note).
 *
 * A single immutable value object so the document templates have
 * one consistent shape to render — name + title + footer always
 * present, the letterhead block (logo / address / VAT / reg /
 * phone / email) optional.  Factory methods cover the three
 * issuers we support:
 *
 *   - forProselver()  the platform's own branded note (default).
 *   - forCompany()    a dealer / OEM printing on their own
 *                     letterhead (logo + address + VAT + reg).
 *   - forCourier()    a 3rd-party courier (name only, no branding).
 */
readonly class IssuerProfile
{
    public function __construct(
        public string $name,
        public string $docTitle,
        public string $footer,
        public ?string $logoUri = null,
        public ?string $address = null,
        public ?string $vatNumber = null,
        public ?string $registrationNumber = null,
        public ?string $phone = null,
        public ?string $email = null,
    ) {
    }

    /**
     * The platform's own branded note — preserves the exact name,
     * title, footer and logo the PDF used before the Phase 1B
     * refactor so ProSelver-executed jobs render byte-identical.
     */
    public static function forProselver(string $docTitle = 'Collection Note'): self
    {
        return new self(
            name: 'Proselver Technologies',
            docTitle: $docTitle,
            footer: 'Proselver Technologies (Pty) Ltd — dispatched via TRIDENT Control & Dispatch Center',
            logoUri: DocumentImage::fromLocalPath(public_path('proselverlogo-2.png')),
        );
    }

    /**
     * A dealer / OEM printing on their own letterhead.  Pulls the
     * logo off the configured storage disk and the address / VAT /
     * registration / phone / email straight from the company row.
     * When the company hasn't filled in their branding the optional
     * fields stay null and the template degrades to a clean
     * name-only masthead.
     */
    public static function forCompany(Company $company, string $docTitle, ?string $footerOverride = null): self
    {
        $footer = $footerOverride
            ?? ($company->branding_footer
                ?: (($company->name ?: 'Dealer') . ' — issued via TRIDENT Control & Dispatch Center'));

        return new self(
            name: $company->name ?: 'Dealer-managed movement',
            docTitle: $docTitle,
            footer: $footer,
            logoUri: DocumentImage::fromDisk($company->logo_path),
            address: $company->address ?: null,
            vatNumber: $company->vat_number ?: null,
            registrationNumber: $company->registration_number ?: null,
            phone: $company->phone ?: null,
            email: $company->billing_email ?: null,
        );
    }

    /**
     * A 3rd-party courier — name only, no branding block, since we
     * don't hold a Company row (let alone a logo) for them.
     */
    public static function forCourier(string $courierName, string $docTitle = 'Delivery Note'): self
    {
        $name = $courierName ?: '3rd-Party Courier';

        return new self(
            name: $name,
            docTitle: $docTitle,
            footer: 'Movement by ' . $name . ' — issued via TRIDENT Control & Dispatch Center',
        );
    }

    /**
     * True when there's at least one letterhead detail worth
     * rendering — the template guards the address/VAT block with
     * this so an issuer with no branding doesn't print an empty box.
     */
    public function hasLetterhead(): bool
    {
        return (bool) ($this->address || $this->vatNumber || $this->registrationNumber || $this->phone || $this->email);
    }
}

<?php

use App\Models\BodyBuilderDealerLink;
use App\Models\BodyBuilderRequest;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Services\BodyBuilderRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Behaviour the dealer flow depends on:
 *
 *   - approveAsNew mints a body_builder Company + a body_builder
 *     Location and auto-links the requesting dealer.
 *   - mergeInto leaves the existing Company alone but auto-links the
 *     dealer to it.
 *   - reject closes the request without touching company tables.
 *   - findDedupeCandidates returns fuzzy matches against existing BBs
 *     so dealers and ops both see "did you mean...?".
 *   - Re-deciding an already-resolved request throws.
 */

function bbReqMakeDealer(string $name = 'Test Dealer'): Company
{
    return Company::create(['name' => $name, 'type' => Company::TYPE_DEALER]);
}

function bbReqMakeBb(string $name, ?string $address = null): Company
{
    return Company::create(['name' => $name, 'type' => Company::TYPE_BODY_BUILDER, 'address' => $address]);
}

function bbReqMakeOps(): User
{
    return User::factory()->create();
}

function bbReqMakeRequest(Company $dealer, array $overrides = []): BodyBuilderRequest
{
    return BodyBuilderRequest::create(array_merge([
        'dealer_company_id'    => $dealer->id,
        'requested_by_user_id' => User::factory()->create()->id,
        'proposed_name'        => 'Anchor Auto Body Builders',
        'proposed_address'     => '6 Argon Rd, Springs, Gauteng',
        'status'               => BodyBuilderRequest::STATUS_PENDING,
    ], $overrides));
}

test('approveAsNew mints a body_builder Company and seeds a Location', function () {
    $dealer = bbReqMakeDealer();
    $req    = bbReqMakeRequest($dealer);
    $ops    = bbReqMakeOps();

    $bb = app(BodyBuilderRequestService::class)->approveAsNew($req, $ops, 'Fresh BB');

    expect($bb->type)->toBe(Company::TYPE_BODY_BUILDER);
    expect($bb->name)->toBe($req->proposed_name);

    expect(Location::where('company_id', $bb->id)->where('type', Location::TYPE_BODY_BUILDER)->count())->toBe(1);

    $req->refresh();
    expect($req->status)->toBe(BodyBuilderRequest::STATUS_APPROVED);
    expect($req->resolved_body_builder_company_id)->toBe($bb->id);
    expect($req->decided_by_user_id)->toBe($ops->id);
    expect($req->decision_notes)->toBe('Fresh BB');
});

test('approveAsNew auto-links the requesting dealer to the new BB', function () {
    $dealer = bbReqMakeDealer();
    $req    = bbReqMakeRequest($dealer);

    $bb = app(BodyBuilderRequestService::class)->approveAsNew($req, bbReqMakeOps());

    $link = BodyBuilderDealerLink::where('dealer_company_id', $dealer->id)
        ->where('body_builder_company_id', $bb->id)
        ->first();

    expect($link)->not->toBeNull();
    expect($link->is_active)->toBeTrue();
});

test('mergeInto links the dealer to the existing BB without creating a new Company', function () {
    $dealer   = bbReqMakeDealer();
    $existing = bbReqMakeBb('Anchor Auto Body Builders CC', '6 Argon Rd, Springs');
    $req      = bbReqMakeRequest($dealer);

    $countBefore = Company::where('type', Company::TYPE_BODY_BUILDER)->count();

    $resolved = app(BodyBuilderRequestService::class)->mergeInto($req, $existing, bbReqMakeOps(), 'They trade as CC');

    expect(Company::where('type', Company::TYPE_BODY_BUILDER)->count())->toBe($countBefore);
    expect($resolved->id)->toBe($existing->id);

    $req->refresh();
    expect($req->status)->toBe(BodyBuilderRequest::STATUS_MERGED);
    expect($req->resolved_body_builder_company_id)->toBe($existing->id);

    expect(BodyBuilderDealerLink::where('dealer_company_id', $dealer->id)
        ->where('body_builder_company_id', $existing->id)
        ->where('is_active', true)
        ->exists())->toBeTrue();
});

test('mergeInto rejects a non-body-builder target', function () {
    $dealer   = bbReqMakeDealer();
    $notBb    = Company::create(['name' => 'Some OEM', 'type' => Company::TYPE_OEM]);
    $req      = bbReqMakeRequest($dealer);

    app(BodyBuilderRequestService::class)->mergeInto($req, $notBb, bbReqMakeOps());
})->throws(InvalidArgumentException::class);

test('reject closes the request without creating anything', function () {
    $dealer = bbReqMakeDealer();
    $req    = bbReqMakeRequest($dealer);

    $countBefore = Company::where('type', Company::TYPE_BODY_BUILDER)->count();
    $linksBefore = BodyBuilderDealerLink::count();

    app(BodyBuilderRequestService::class)->reject($req, bbReqMakeOps(), 'Duplicate of another open ticket');

    expect(Company::where('type', Company::TYPE_BODY_BUILDER)->count())->toBe($countBefore);
    expect(BodyBuilderDealerLink::count())->toBe($linksBefore);

    $req->refresh();
    expect($req->status)->toBe(BodyBuilderRequest::STATUS_REJECTED);
    expect($req->decision_notes)->toBe('Duplicate of another open ticket');
});

test('re-deciding an already resolved request throws', function () {
    $dealer = bbReqMakeDealer();
    $req    = bbReqMakeRequest($dealer);
    $svc    = app(BodyBuilderRequestService::class);

    $svc->reject($req, bbReqMakeOps());

    $svc->approveAsNew($req->fresh(), bbReqMakeOps());
})->throws(RuntimeException::class);

test('findDedupeCandidates surfaces close-name matches', function () {
    bbReqMakeBb('Anchor Auto Body Builders CC', '6 Argon Rd, Springs');
    bbReqMakeBb('Toro Truck Bodies', 'Potchefstroom');
    bbReqMakeBb('Ice Cold Bodies Heidelberg', 'Heidelberg');

    $hits = app(BodyBuilderRequestService::class)
        ->findDedupeCandidates('Anchor Auto', null);

    expect(collect($hits)->pluck('name'))->toContain('Anchor Auto Body Builders CC');
});

test('findDedupeCandidates returns empty for blank / very short queries', function () {
    bbReqMakeBb('Anchor Auto Body Builders CC');

    expect(app(BodyBuilderRequestService::class)->findDedupeCandidates(''))->toBeEmpty();
    expect(app(BodyBuilderRequestService::class)->findDedupeCandidates('A'))->toBeEmpty();
});

test('linking the same dealer twice is idempotent and reactivates a paused link', function () {
    $dealer = bbReqMakeDealer();
    $bb     = bbReqMakeBb('Anchor Auto', '6 Argon Rd');

    $existing = BodyBuilderDealerLink::create([
        'dealer_company_id'       => $dealer->id,
        'body_builder_company_id' => $bb->id,
        'is_active'               => false,
        'notes'                   => 'Previously paused by dealer',
    ]);

    $req = bbReqMakeRequest($dealer);
    app(BodyBuilderRequestService::class)->mergeInto($req, $bb, bbReqMakeOps());

    expect(BodyBuilderDealerLink::where('dealer_company_id', $dealer->id)
        ->where('body_builder_company_id', $bb->id)
        ->count())->toBe(1);

    expect($existing->fresh()->is_active)->toBeTrue();
});

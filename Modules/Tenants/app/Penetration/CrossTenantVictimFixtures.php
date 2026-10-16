<?php

namespace Modules\Tenants\Penetration;

use Modules\BCC\Models\BCC;
use Modules\Donations\Models\DonationPayment;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\PastoralCare\Models\PastoralCareRequest;
use Modules\Sacraments\Models\Sacrament;
use Modules\Tenants\Models\TenantDataExport;

/**
 * Victim-side records in tenant B for cross-tenant penetration probes.
 */
final class CrossTenantVictimFixtures
{
    public function __construct(
        public readonly Family $family,
        public readonly FamilyMember $member,
        public readonly Person $person,
        public readonly Sacrament $sacrament,
        public readonly DonationPayment $payment,
        public readonly PastoralCareRequest $pastoralRequest,
        public readonly BCC $bcc,
        public readonly TenantDataExport $export,
    ) {}
}

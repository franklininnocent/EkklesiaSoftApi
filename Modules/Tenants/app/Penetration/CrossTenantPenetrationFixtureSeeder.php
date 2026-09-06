<?php

namespace Modules\Tenants\Penetration;

use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Donations\Models\DonationPayment;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\PastoralCare\Models\PastoralCareRequest;
use Modules\PastoralCare\Support\PastoralCareType;
use Modules\Sacraments\Models\Sacrament;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Models\TenantDataExport;
use Modules\Tenants\Penetration\CrossTenantVictimFixtures;

class CrossTenantPenetrationFixtureSeeder
{
    public function seedVictimTenant(?Tenant $tenant = null): CrossTenantVictimFixtures
    {
        $tenant ??= Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
            'features' => ['donations', 'events', 'groups'],
        ]);

        $family = Family::factory()->active()->create([
            'tenant_id' => $tenant->id,
            'family_name' => 'Victim Foreign Family',
        ]);

        $person = Person::factory()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Victim',
            'last_name' => 'Person',
        ]);

        $member = FamilyMember::factory()->active()->create([
            'tenant_id' => $tenant->id,
            'family_id' => $family->id,
            'person_id' => $person->id,
            'first_name' => 'Victim',
            'last_name' => 'Member',
        ]);

        $sacrament = Sacrament::factory()->registered()->create([
            'tenant_id' => $tenant->id,
            'family_id' => $family->id,
            'person_id' => $person->id,
            'recipient_name' => 'Victim Sacrament Recipient',
        ]);

        $payment = DonationPayment::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-VICTIM-'.substr(uniqid(), -6),
            'payer_name' => 'Victim Payer',
            'payment_date' => now()->toDateString(),
            'amount' => '50.00',
            'currency' => 'USD',
            'method' => 'cash',
            'status' => 'succeeded',
        ]);

        $victimUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'active' => 1,
        ]);

        $pastoralRequest = PastoralCareRequest::factory()->open()->create([
            'tenant_id' => $tenant->id,
            'family_id' => $family->id,
            'type' => PastoralCareType::HOME_VISIT,
            'summary' => 'Victim pastoral request',
            'created_by_user_id' => $victimUser->id,
        ]);

        $bcc = BCC::factory()->active()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Victim BCC',
        ]);

        $export = TenantDataExport::query()->create([
            'tenant_id' => $tenant->id,
            'status' => TenantDataExport::STATUS_COMPLETED,
            'modules' => ['users'],
            'options' => ['include_media' => false],
            'requested_by' => $victimUser->id,
            'file_path' => 'tenants/'.$tenant->id.'/exports/victim-export.zip',
            'expires_at' => now()->addDay(),
        ]);

        return new CrossTenantVictimFixtures(
            family: $family,
            member: $member,
            person: $person,
            sacrament: $sacrament,
            payment: $payment,
            pastoralRequest: $pastoralRequest,
            bcc: $bcc,
            export: $export,
        );
    }
}

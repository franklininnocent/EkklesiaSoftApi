<?php

namespace Modules\BCC\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\BCC\Models\BCC;
use Modules\Tenants\Models\Tenant;

/**
 * Adds Basic Christian Communities for Sacred Heart Church (demo / parish data).
 * Never deletes or updates existing rows — only inserts communities whose names are not yet present.
 */
class SacredHeartChurchBccsSeeder extends Seeder
{
    private const TENANT_NAMES = [
        'Sacred Heart Church',
        'Sacred Heart Parish',
    ];

    public function run(): void
    {
        $tenant = $this->resolveTenant();

        if (! $tenant) {
            $this->command?->error(
                'Tenant not found. Expected name like "Sacred Heart Church". Create the parish tenant first.'
            );

            return;
        }

        $definitions = $this->communityDefinitions();
        $created = 0;
        $skipped = 0;

        foreach ($definitions as $row) {
            $exists = BCC::query()
                ->where('tenant_id', $tenant->id)
                ->where('name', $row['name'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            BCC::create(array_merge($row, ['tenant_id' => $tenant->id]));
            $created++;
        }

        $total = BCC::query()->where('tenant_id', $tenant->id)->count();

        $this->command?->info(sprintf(
            'Sacred Heart Church BCCs: %d created, %d skipped (already present), %d total for tenant #%d (%s). (%d definitions in catalog.)',
            $created,
            $skipped,
            $total,
            $tenant->id,
            $tenant->name,
            count($definitions)
        ));
    }

    private function resolveTenant(): ?Tenant
    {
        foreach (self::TENANT_NAMES as $name) {
            $tenant = Tenant::query()->where('name', $name)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        return Tenant::query()
            ->where('name', 'ILIKE', '%Sacred Heart%')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function communityDefinitions(): array
    {
        $communities = array_merge($this->primaryCommunityTuples(), $this->additionalCommunityTuples());

        return $this->buildCommunityRows($communities);
    }

    /**
     * Original 30 communities.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private function primaryCommunityTuples(): array
    {
        return [
            ['St. Anthony Colony', 'North gate residential block', 'St. Anthony Hall', 'sunday', '09:00', 'Weekly'],
            ['Our Lady of Lourdes Ward', 'Lourdes Hill lane families', 'Lourdes grotto courtyard', 'sunday', '10:30', 'Weekly'],
            ['Sacred Heart Cathedral Block', 'Parish campus east wing', 'Parish community hall', 'sunday', '11:00', 'Weekly'],
            ['St. Joseph Workers Unit', 'Industrial estate staff quarters', 'Josephite meeting room', 'wednesday', '19:00', 'Weekly'],
            ['Immaculate Conception Lane', 'Old town market street', 'Convent annex', 'thursday', '18:30', 'Weekly'],
            ['St. Sebastian Sports Club Area', 'Stadium road neighborhood', 'Youth center pavilion', 'friday', '19:30', 'Weekly'],
            ['Holy Family Housing Board', 'Phase II flats', 'Block B common room', 'saturday', '17:00', 'Weekly'],
            ['St. Francis Assisi Garden', 'Green valley layout', 'Franciscan prayer shed', 'sunday', '08:30', 'Weekly'],
            ['St. Teresa Carmel Hill', 'Carmel convent vicinity', 'Carmel hall basement', 'tuesday', '18:00', 'Bi-weekly'],
            ['St. Michael Guardians Unit', 'Police quarters colony', 'Community library', 'sunday', '16:00', 'Weekly'],
            ['St. Jude Hope Circle', 'Cancer care volunteer families', 'Jude prayer chapel', 'monday', '19:00', 'Weekly'],
            ['St. Alphonsa Village', 'Rubber estate labor lines', 'Estate club house', 'sunday', '15:00', 'Weekly'],
            ['St. Thomas Apostle Bay', 'Fishermen\'s coast road', 'Boat yard shed', 'saturday', '18:00', 'Weekly'],
            ['St. Peter Rock Heights', 'Hilltop housing', 'Open-air terrace', 'sunday', '17:30', 'Weekly'],
            ['St. Paul Mission Outreach', 'New converts fellowship', 'Parish catechism room', 'thursday', '19:00', 'Weekly'],
            ['St. John Bosco Youth Block', 'Don Bosco school alumni area', 'School auditorium wing', 'friday', '18:00', 'Weekly'],
            ['St. Anne Mothers Circle', 'Women\'s self-help network', 'Anne memorial hall', 'wednesday', '10:00', 'Bi-weekly'],
            ['St. Vincent de Paul Service', 'Charity pantry volunteers', 'SVP store room', 'tuesday', '17:30', 'Weekly'],
            ['St. Maria Goretti Girls Hostel', 'Working women hostel', 'Hostel dining hall', 'sunday', '19:00', 'Weekly'],
            ['St. Ignatius Professionals', 'IT park commuters', 'Ignatius retreat house', 'saturday', '08:00', 'Monthly'],
            ['St. Luke Health Workers', 'Hospital staff colony', 'Nurses\' rest room', 'monday', '20:00', 'Weekly'],
            ['St. Mark Gospel Readers', 'Scripture study group area', 'Parish media room', 'tuesday', '19:30', 'Weekly'],
            ['St. Mathew Tax Collectors Lane', 'Market traders guild', 'Market committee office', 'thursday', '07:30', 'Weekly'],
            ['St. Simon Cyrene Helpers', 'Funeral and bereavement support', 'Parish mortuary annex', 'friday', '16:30', 'Bi-weekly'],
            ['St. Bartholomew Tribal Settlement', 'Hill tribe mission outstation', 'Tribal chapel', 'sunday', '14:00', 'Monthly'],
            ['St. Philip Deacon Block', 'Altar servers and liturgy team', 'Sacristy meeting nook', 'saturday', '09:30', 'Weekly'],
            ['St. James Pilgrim Way', 'Highway motel workers', 'Travelers\' chapel', 'wednesday', '20:30', 'Weekly'],
            ['St. Andrew Fisherfolk Net', 'Inland lake fishermen', 'Net mending shed', 'sunday', '06:30', 'Weekly'],
            ['St. Matthias Renewal Group', 'Returned migrants fellowship', 'Parish hall side room', 'saturday', '19:00', 'Weekly'],
            ['St. Stephen Martyr Colony', 'Stone quarry labor camp', 'Quarry welfare center', 'sunday', '18:00', 'Weekly'],
        ];
    }

    /**
     * Additional communities (inserted when missing; existing names are left unchanged).
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private function additionalCommunityTuples(): array
    {
        return [
            ['Christ the King Crown', 'Royal road extension', 'Kingdom hall terrace', 'sunday', '09:15', 'Weekly'],
            ['Our Lady of Fatima Hill', 'Fatima shrine approach', 'Shrine veranda', 'saturday', '17:30', 'Weekly'],
            ['St. Rita Patience Circle', 'Family counseling network', 'Rita garden gazebo', 'tuesday', '18:45', 'Weekly'],
            ['St. Monica Widows Fellowship', 'Elder care lane', 'Monica house parlor', 'thursday', '15:00', 'Bi-weekly'],
            ['St. Elizabeth Cousins Unit', 'Visitation ministry area', 'Elizabeth outreach room', 'wednesday', '11:00', 'Weekly'],
            ['St. Martha Hospitality Team', 'Parish kitchen volunteers', 'Martha refectory', 'friday', '09:00', 'Weekly'],
            ['St. Lazarus Healing Prayer', 'Chronic illness support', 'Healing chapel side aisle', 'monday', '18:00', 'Weekly'],
            ['St. Veronica Compassion Lane', 'Hospital visitor teams', 'Veronica station nook', 'sunday', '07:00', 'Weekly'],
            ['St. Dismas Second Chance', 'Prison ministry families', 'Reconciliation room', 'saturday', '16:00', 'Monthly'],
            ['St. Joan of Arc Courage', 'Women entrepreneurs cluster', 'Joan hall mezzanine', 'wednesday', '19:15', 'Weekly'],
            ['St. Patrick Emerald Block', 'Irish heritage families', 'Patrick cultural center', 'sunday', '12:00', 'Monthly'],
            ['St. Cecilia Music Ministry', 'Choir and cantor families', 'Cecilia choir loft room', 'tuesday', '20:00', 'Weekly'],
            ['St. Blaise Throat Blessing Unit', 'February blessing walk', 'Blaise side altar', 'friday', '06:45', 'Monthly'],
            ['St. Christopher Travelers Rest', 'Bus depot neighborhood', 'Christopher shelter', 'thursday', '21:00', 'Weekly'],
            ['St. Expeditus Urgent Needs', 'Emergency aid responders', 'Expeditus store cupboard', 'monday', '17:00', 'Weekly'],
            ['St. Faustina Mercy Diary', 'Divine mercy cenacle', 'Faustina image corner', 'sunday', '15:30', 'Weekly'],
            ['St. Kuriakose Elias Block', 'Syro-Malabar faithful cluster', 'Kuriakose hall wing', 'friday', '19:00', 'Weekly'],
            ['St. Chavara Education Circle', 'School parent volunteers', 'Chavara library alcove', 'saturday', '10:00', 'Weekly'],
            ['St. Euphrasia Rose Garden', 'Carmelite associate families', 'Rose garden bench circle', 'sunday', '18:30', 'Bi-weekly'],
            ['St. Gonsalo Garcia Mission', 'Japanese martyrs devotion group', 'Garcia memorial corner', 'wednesday', '18:15', 'Monthly'],
            ['St. Roque Plague Remembrance', 'Public health volunteers', 'Roque wayside shrine', 'tuesday', '07:30', 'Monthly'],
            ['St. Maximilian Kolbe Block', 'Pro-life and family defense', 'Kolbe hall basement', 'thursday', '19:45', 'Weekly'],
            ['St. Therese Little Flower Way', 'Children\'s catechism parents', 'Therese classroom annex', 'saturday', '09:00', 'Weekly'],
            ['St. Benedict Rule Keepers', 'Oblates and associates', 'Benedictine study cell', 'monday', '06:00', 'Weekly'],
            ['St. Scholastica Twin Sisters', 'Twin parish outreach', 'Scholastica convent gate', 'sunday', '20:00', 'Bi-weekly'],
            ['St. Damien Leper Colony Support', 'Leprosy home volunteers', 'Damien service desk', 'friday', '14:30', 'Monthly'],
            ['St. Marianne Cope Health', 'Clinic aide families', 'Cope wellness room', 'wednesday', '16:45', 'Weekly'],
            ['St. Padre Pio Stigmata Group', 'Friday confession helpers', 'Pio chapel anteroom', 'friday', '17:15', 'Weekly'],
            ['St. Oscar Romero Justice', 'Land-rights advocacy families', 'Romero justice desk', 'tuesday', '19:00', 'Monthly'],
            ['St. Mother Teresa Missionaries', 'Home for dying companions', 'Teresa house courtyard', 'sunday', '08:00', 'Weekly'],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>  $communities
     * @return list<array<string, mixed>>
     */
    private function buildCommunityRows(array $communities): array
    {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $freqs = ['Weekly', 'Bi-weekly', 'Monthly'];

        $rows = [];
        foreach ($communities as $index => [$name, $area, $place, $day, $time, $frequency]) {
            $established = now()->subYears(3 + ($index % 20))->subMonths($index % 12)->startOfMonth()->toDateString();
            $rows[] = [
                'name' => $name,
                'description' => sprintf(
                    'Basic Christian Community serving families in %s under Sacred Heart Church pastoral care.',
                    $area
                ),
                'location' => $area,
                'meeting_place' => $place,
                'meeting_day' => $day,
                'meeting_time' => $time,
                'meeting_frequency' => $frequency,
                'status' => $this->statusForIndex($index),
                'established_date' => $established,
                'notes' => $index % 5 === 0
                    ? 'Coordinator visit logged quarterly; share prayer intentions with parish office.'
                    : null,
            ];
        }

        foreach ($rows as &$row) {
            if (! in_array($row['meeting_day'], $days, true)) {
                $row['meeting_day'] = 'sunday';
            }
            if (! in_array($row['meeting_frequency'], $freqs, true)) {
                $row['meeting_frequency'] = 'Weekly';
            }
        }
        unset($row);

        return $rows;
    }

    private function statusForIndex(int $index): string
    {
        if ($index === 27 || $index === 57) {
            return 'inactive';
        }
        if ($index === 19 || $index === 49) {
            return 'suspended';
        }

        return 'active';
    }
}

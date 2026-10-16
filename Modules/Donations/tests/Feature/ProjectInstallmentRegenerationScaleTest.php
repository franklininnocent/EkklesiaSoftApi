<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Services\ProjectInstallmentDueService;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Measures guarded bulk regeneration. Not part of the default fast suite.
 */
#[Group('installment-scale')]
class ProjectInstallmentRegenerationScaleTest extends DonationsCertificationTestCase
{
    /**
     * @return array<string, array{0: int}>
     */
    public static function familyCounts(): array
    {
        return [
            '100 families' => [100],
            '500 families' => [500],
            '1000 families' => [1000],
            '5000 families' => [5000],
        ];
    }

    #[Test]
    #[DataProvider('familyCounts')]
    public function guarded_bulk_regeneration_stays_correct_at_parish_scale(int $families): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $tenantId = (int) $ctx['tenant']->id;
        $userId = (int) $ctx['user']->id;
        $now = now()->toDateTimeString();
        $familyIds = [];

        foreach (array_chunk(range(1, $families), 500) as $chunk) {
            $rows = [];
            foreach ($chunk as $index) {
                $id = (string) Str::uuid();
                $familyIds[] = $id;
                $rows[] = [
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'family_code' => 'SCALE-'.$families.'-'.$index,
                    'family_name' => 'Scale '.$index,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('families')->insert($rows);
        }

        $project = DonationProject::create([
            'tenant_id' => $tenantId,
            'name' => 'Scale Roof '.$families,
            'code' => 'SCALE-'.$families.'-'.substr(uniqid(), -4),
            'assignment_mode' => 'uniform',
            'default_family_target' => 12000,
            'installment_count' => 12,
            'installment_frequency' => 'monthly',
            'start_date' => now()->startOfYear()->toDateString(),
            'status' => 'active',
            'auto_generate_installments' => false,
            'raised_amount' => 0,
        ]);

        $service = app(ProjectInstallmentDueService::class);

        $generateStarted = hrtime(true);
        $generated = $service->generateForProject($tenantId, $userId, $project);
        $generateMs = (hrtime(true) - $generateStarted) / 1e6;
        $this->assertSame('generated', $generated['outcome']);
        $this->assertSame($families * 12, (int) $generated['created']);

        $lockedFamily = $familyIds[0];
        $lockedDue = ProjectInstallmentDue::where('project_id', $project->id)
            ->where('family_id', $lockedFamily)
            ->where('installment_number', 1)
            ->firstOrFail();
        $lockedDue->amount_paid = '100.00';
        $lockedDue->status = 'partially_paid';
        $lockedDue->save();
        $lockedSnapshot = ProjectInstallmentDue::where('project_id', $project->id)
            ->where('family_id', $lockedFamily)
            ->orderBy('installment_number')
            ->get(['id', 'installment_number', 'amount_due', 'amount_paid', 'status'])
            ->map(fn (ProjectInstallmentDue $due): array => [
                'id' => $due->id,
                'amount_due' => number_format((float) $due->amount_due, 2, '.', ''),
                'amount_paid' => number_format((float) $due->amount_paid, 2, '.', ''),
                'status' => $due->status,
            ])->all();

        $project->default_family_target = 24000;
        $project->save();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $memoryBefore = memory_get_usage(true);
        $started = hrtime(true);
        $regenerated = $service->generateForProject(
            $tenantId,
            $userId,
            $project->fresh(),
            null,
            'regenerate',
            'Council doubled the family target before most families had paid.'
        );
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $memoryAfter = memory_get_usage(true);

        $updateQueries = 0;
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            if (str_contains($sql, 'project_installment_dues') && str_starts_with(ltrim($sql), 'update')) {
                $updateQueries++;
            }
        }

        fwrite(STDERR, sprintf(
            "\n[scale %d] generate %.0f ms | regenerate %.0f ms | queries %d | guarded updates %d | memory %+0.1f MB | locked %d | updated %d\n",
            $families,
            $generateMs,
            $elapsedMs,
            count($queries),
            $updateQueries,
            ($memoryAfter - $memoryBefore) / 1048576,
            (int) $regenerated['families_locked'],
            (int) $regenerated['updated']
        ));

        $this->assertSame('regenerated', $regenerated['outcome']);
        $this->assertSame(1, (int) $regenerated['families_locked']);
        $this->assertLessThan($families, $updateQueries, 'Guarded updates must be chunked, not one query per family.');

        $lockedNow = ProjectInstallmentDue::where('project_id', $project->id)
            ->where('family_id', $lockedFamily)
            ->orderBy('installment_number')
            ->get();
        $this->assertCount(12, $lockedNow);
        foreach ($lockedNow as $index => $due) {
            $this->assertSame($lockedSnapshot[$index]['id'], $due->id);
            $this->assertSame($lockedSnapshot[$index]['amount_due'], number_format((float) $due->amount_due, 2, '.', ''));
            $this->assertSame($lockedSnapshot[$index]['amount_paid'], number_format((float) $due->amount_paid, 2, '.', ''));
            $this->assertSame($lockedSnapshot[$index]['status'], $due->status);
            $this->assertSame($tenantId, (int) $due->tenant_id);
        }

        $openFamily = $familyIds[1];
        $open = ProjectInstallmentDue::where('project_id', $project->id)
            ->where('family_id', $openFamily)
            ->orderBy('installment_number')
            ->get();
        $this->assertCount(12, $open);
        $sum = '0.00';
        foreach ($open as $due) {
            $this->assertSame('pending', $due->status);
            $this->assertSame('0.00', number_format((float) $due->amount_paid, 2, '.', ''));
            $this->assertSame($tenantId, (int) $due->tenant_id);
            $sum = MoneyMath::add($sum, $due->amount_due);
        }
        $this->assertSame('24000.00', $sum);
        $this->assertSame(
            $families * 12,
            ProjectInstallmentDue::where('project_id', $project->id)->count()
        );

        $outstanding = '0.00';
        ProjectInstallmentDue::where('project_id', $project->id)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->orderBy('id')
            ->chunk(1000, function ($dues) use (&$outstanding): void {
                foreach ($dues as $due) {
                    $outstanding = MoneyMath::add($outstanding, MoneyMath::outstanding($due->amount_due, $due->amount_paid));
                }
            });
        $lockedOutstanding = MoneyMath::outstanding('1000.00', '100.00');
        for ($i = 0; $i < 11; $i++) {
            $lockedOutstanding = MoneyMath::add($lockedOutstanding, '1000.00');
        }
        $expected = MoneyMath::add($lockedOutstanding, MoneyMath::normalize((string) (($families - 1) * 24000)));
        $this->assertSame($expected, $outstanding);
    }
}

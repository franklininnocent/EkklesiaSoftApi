<?php

namespace Modules\Donations\Services\Reports;

use InvalidArgumentException;
use Modules\Donations\Contracts\DonationReportBuilderContract;
use Modules\Donations\Support\Reports\DonationReportCatalog;

final class DonationReportBuilderRegistry
{
    public function resolve(string $reportType): DonationReportBuilderContract
    {
        $definition = DonationReportCatalog::definition($reportType);
        $class = $definition['builder'];

        $builder = app($class);
        if (! $builder instanceof DonationReportBuilderContract) {
            throw new InvalidArgumentException('Report builder is misconfigured.');
        }

        return $builder;
    }
}

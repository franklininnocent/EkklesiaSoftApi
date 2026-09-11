<?php

namespace Modules\ApplicationAccess\Contracts;

/**
 * Optional GeoIP enrichment (local MMDB in a later phase).
 */
interface GeoIpReader
{
    /**
     * @return array{
     *     country: ?string,
     *     region: ?string,
     *     city: ?string,
     *     latitude: ?float,
     *     longitude: ?float,
     *     timezone: ?string,
     *     geo_source: ?string,
     *     geo_status: string
     * }
     */
    public function lookup(string $ipAddress, string $ipClass): array;
}

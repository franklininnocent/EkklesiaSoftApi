<?php

namespace Modules\ApplicationAccess\Support;

use Modules\ApplicationAccess\Contracts\GeoIpReader;

final class NullGeoIpReader implements GeoIpReader
{
    public function lookup(string $ipAddress, string $ipClass): array
    {
        if (in_array($ipClass, [
            IpAddressClassifier::CLASS_PRIVATE,
            IpAddressClassifier::CLASS_LOOPBACK,
            IpAddressClassifier::CLASS_INTERNAL,
        ], true)) {
            return [
                'country' => null,
                'region' => null,
                'city' => null,
                'latitude' => null,
                'longitude' => null,
                'timezone' => null,
                'geo_source' => null,
                'geo_status' => 'PRIVATE_NETWORK',
            ];
        }

        return [
            'country' => null,
            'region' => null,
            'city' => null,
            'latitude' => null,
            'longitude' => null,
            'timezone' => null,
            'geo_source' => null,
            'geo_status' => 'LOOKUP_FAILED',
        ];
    }
}

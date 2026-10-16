<?php

namespace Modules\Tenants\Database\Seeders\Support;

use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Services\Certificates\MarriageCertificateRequirementsValidator;
use Modules\Sacraments\Support\SacramentTypeCode;

/**
 * Whether a register row has the data required to issue its sacrament certificate.
 */
final class SacramentsRegisterCertificateReadiness
{
    public static function isReady(Sacrament $sacrament): bool
    {
        $sacrament->loadMissing('sacramentType');
        $code = SacramentTypeCode::normalize($sacrament->sacramentType?->code);

        if ($code === SacramentTypeCode::MATRIMONY) {
            return (new MarriageCertificateRequirementsValidator)->missingFields($sacrament) === [];
        }

        if ($code === SacramentTypeCode::BAPTISM) {
            return self::filled($sacrament->recipient_name)
                && self::filled($sacrament->father_name)
                && self::filled($sacrament->mother_name);
        }

        if (in_array($code, [SacramentTypeCode::CONFIRMATION, SacramentTypeCode::EUCHARIST], true)) {
            return self::filled($sacrament->recipient_name);
        }

        return true;
    }

    private static function filled(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return trim((string) $value) !== '';
    }
}

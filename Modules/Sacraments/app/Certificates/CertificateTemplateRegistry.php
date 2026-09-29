<?php

namespace Modules\Sacraments\Certificates;

use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Support\SacramentTypeCode;

/**
 * Code-defined certificate templates (ADR-09 / Phase 10).
 */
final class CertificateTemplateRegistry
{
    public const BAPTISM_V1 = 'baptism_v1';

    public const MARRIAGE_V1 = 'marriage_v1';

    public const CONFIRMATION_V1 = 'confirmation_v1';

    public const EUCHARIST_FIRST_COMMUNION_V1 = 'eucharist_first_communion_v1';

    public const ANOINTING_V1 = 'anointing_v1';

    public const HOLY_ORDERS_V1 = 'holy_orders_v1';

    public const RECONCILIATION_V1 = 'reconciliation_v1';

    /**
     * @return array{template_code:string, template_version:string, title:string}
     */
    public function forTypeCode(string $typeCode): array
    {
        $code = SacramentTypeCode::normalize($typeCode) ?? strtoupper($typeCode);

        return match ($code) {
            SacramentTypeCode::BAPTISM => [
                'template_code' => self::BAPTISM_V1,
                'template_version' => '1.0.0',
                'title' => 'Certificate of Baptism',
            ],
            SacramentTypeCode::MATRIMONY => [
                'template_code' => self::MARRIAGE_V1,
                'template_version' => '1.1.0',
                'title' => 'Certificate of Holy Matrimony',
            ],
            SacramentTypeCode::CONFIRMATION => [
                'template_code' => self::CONFIRMATION_V1,
                'template_version' => '1.0.0',
                'title' => 'Certificate of Confirmation',
            ],
            SacramentTypeCode::EUCHARIST => [
                'template_code' => self::EUCHARIST_FIRST_COMMUNION_V1,
                'template_version' => '1.0.0',
                'title' => 'Certificate of First Holy Communion',
            ],
            SacramentTypeCode::ANOINTING => [
                'template_code' => self::ANOINTING_V1,
                'template_version' => '1.0.0',
                'title' => 'Certificate of Anointing of the Sick',
            ],
            SacramentTypeCode::HOLY_ORDERS => [
                'template_code' => self::HOLY_ORDERS_V1,
                'template_version' => '1.0.0',
                'title' => 'Certificate of Holy Orders',
            ],
            SacramentTypeCode::RECONCILIATION => [
                'template_code' => self::RECONCILIATION_V1,
                'template_version' => '1.0.0',
                'title' => 'Certificate of Reconciliation',
            ],
            default => throw new SacramentBusinessRuleException(
                'certificate_not_supported',
                'No certificate template for this sacrament type.'
            ),
        };
    }
}

<?php

namespace Modules\SupportAccess\Support;

use Modules\SupportAccess\Models\SupportSession;
use Modules\Tenants\Services\ChurchCurrencyResolver;

final class SupportSessionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(SupportSession $session): array
    {
        $data = $session->toArray();
        $resolver = app(ChurchCurrencyResolver::class);
        $data['currency'] = $resolver->forTenantId((int) $session->tenant_id)?->toArray();

        return $data;
    }
}

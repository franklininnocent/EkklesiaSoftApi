<?php

namespace Modules\ApplicationAccess\Support;

use Illuminate\Http\Request;

final class ApplicationAccessCaptureExemptions
{
    /** @var list<string> */
    private const EXEMPT_PREFIXES = [
        'up',
        'api/admin/application-access',
        'api/tenant/media/serve',
        'api/media/serve',
    ];

    public function isExempt(Request $request): bool
    {
        if ($request->isMethod('OPTIONS')) {
            return true;
        }

        $path = trim($request->path(), '/');

        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}

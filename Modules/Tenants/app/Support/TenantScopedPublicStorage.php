<?php

namespace Modules\Tenants\Support;

use Modules\Authentication\Models\User;

/**
 * Validates tenant ownership for files on the public disk served via signed URLs.
 */
final class TenantScopedPublicStorage
{
    /**
     * Allowed path prefixes. Tenant id is captured in group 1 for tenant-scoped paths.
     */
    private const TENANT_SCOPED_PATTERN = '#^(?:tenants|families)/(\d+)/.+$#';

    private const GLOBAL_PREFIX_PATTERN = '#^popes/\d+/.+$#';

    /**
     * Normalize and validate a storage path. Returns null when unsafe or disallowed.
     */
    public static function normalizePath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        if (str_contains($path, '..')) {
            return null;
        }

        if (preg_match('#(^|/)\.\./#', $path) || preg_match('#/(^|/)\./#', $path)) {
            return null;
        }

        $path = ltrim($path, '/');

        if (preg_match(self::TENANT_SCOPED_PATTERN, $path)) {
            return $path;
        }

        if (preg_match(self::GLOBAL_PREFIX_PATTERN, $path)) {
            return $path;
        }

        return null;
    }

    public static function extractTenantId(string $path): ?int
    {
        if (preg_match(self::TENANT_SCOPED_PATTERN, $path, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public static function isGlobalPath(string $path): bool
    {
        return (bool) preg_match(self::GLOBAL_PREFIX_PATTERN, $path);
    }

    /**
     * Whether the authenticated user may access this path in the effective tenant.
     */
    public static function userCanAccessPath(string $path, User $user, int $effectiveTenantId): bool
    {
        if ($user->isSuperAdmin() || $user->isEkklesiaAdmin()) {
            return true;
        }

        if (self::isGlobalPath($path)) {
            return true;
        }

        $fileTenantId = self::extractTenantId($path);

        if ($fileTenantId === null) {
            return false;
        }

        return $fileTenantId === $effectiveTenantId;
    }
}

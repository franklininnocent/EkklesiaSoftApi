# Tenant RBAC Rollout Runbook

## 1) Apply migrations

```bash
php artisan migrate
```

Expected key migration:

- `2026_06_11_120000_add_role_type_and_permission_scope`

## 2) Seed role/permission catalog

```bash
php artisan db:seed --class="Modules\\RolesAndPermissions\\Database\\Seeders\\RolesAndPermissionsDatabaseSeeder"
```

This seeds:

- baseline permissions (`PermissionsTableSeeder`)
- tenant catalog permissions (`TenantPermissionCatalogSeeder`)

## 3) Backfill existing tenants

Preview:

```bash
php artisan tenants:backfill-rbac --dry-run
```

Apply:

```bash
php artisan tenants:backfill-rbac
```

Optional cleanup (delete deprecated default roles only when safe):

```bash
php artisan tenants:backfill-rbac --delete-deprecated
```

Optional cleanup with reassignment:

```bash
php artisan tenants:backfill-rbac --delete-deprecated --reassign-to-role="Parish Priest"
```

This command ensures:

- tenant `Administrator` role exists
- default tenant `Parish Priest` role exists (or legacy `Pastor` is renamed)
- legacy `users.role_id` + `role_user` pivot are synchronized for admin users

## 4) Re-sync admin permissions (tenant/both scope only)

Preview:

```bash
php artisan tenants:assign-admin-permissions --dry-run
```

Apply:

```bash
php artisan tenants:assign-admin-permissions
```

## 5) Verify route surface

```bash
php artisan route:list --path=api/tenant
```

Confirm tenant RBAC endpoints are present:

- `/api/tenant/roles*`
- `/api/tenant/permissions`
- `/api/tenant/roles/{roleId}/permissions`
- `/api/tenant/users/{id}/roles`

## 6) Execute automated tests

```bash
php artisan test Modules/RolesAndPermissions/tests/Feature/TenantRbacApiTest.php
php artisan test Modules/RolesAndPermissions/tests/Unit/TenantRoleServiceTest.php Modules/RolesAndPermissions/tests/Unit/TenantRoleAssignmentServiceTest.php
php artisan test Modules/RolesAndPermissions/tests/Unit/TenantPermissionServiceTest.php Modules/RolesAndPermissions/tests/Unit/TenantPermissionCrudServiceTest.php
php artisan test Modules/RolesAndPermissions/tests/Unit/EnsureTenantMiddlewareTest.php Modules/RolesAndPermissions/tests/Unit/EnsureTenantAdminMiddlewareTest.php Modules/RolesAndPermissions/tests/Unit/EnsureTenantPermissionMiddlewareTest.php
php artisan test Modules/RolesAndPermissions/tests/Unit/RolePolicyTest.php Modules/RolesAndPermissions/tests/Unit/PermissionPolicyTest.php Modules/RolesAndPermissions/tests/Unit/AuthGateRegistrationTest.php
php artisan test Modules/RolesAndPermissions/tests/Unit/PermissionAuditServiceTest.php
php artisan test Modules/RolesAndPermissions/tests/Feature/TenantRbacCrossTenantApiTest.php Modules/RolesAndPermissions/tests/Feature/TenantRbacEscalationApiTest.php Modules/RolesAndPermissions/tests/Feature/TenantRbacSecurityPayloadTest.php Modules/RolesAndPermissions/tests/Feature/TenantRbacAuditTest.php
```

## 7) Manual smoke checks

1. Login as church admin.
2. Open tenant role list and verify only same-tenant roles are visible.
3. Attempt to rename/delete `Administrator` role (must fail).
4. Attempt to remove last admin from tenant user-role assignment (must fail).
5. Attempt to assign platform-scope permission (must fail).
6. Assign valid tenant permissions and verify success.

## 8) Rollback guidance

If rollback is needed:

1. Revert application code to previous stable commit.
2. If migration must be reverted:
   ```bash
   php artisan migrate:rollback --step=1
   ```
3. Re-run existing production seeders used before tenant RBAC rollout.

Use rollback only in controlled maintenance window because role and permission mappings may have changed.

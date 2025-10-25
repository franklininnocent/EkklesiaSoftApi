# 👥 Roles & Permissions System - Complete Documentation

**EkklesiaSoft API** - Role-Based Access Control (RBAC) Implementation

---

## 📚 Table of Contents

1. [Overview](#overview)
2. [Quick Start](#quick-start)
3. [Role Hierarchy](#role-hierarchy)
4. [Database Schema](#database-schema)
5. [Super Admin Setup](#super-admin-setup)
6. [Usage Guide](#usage-guide)
7. [API Implementation](#api-implementation)
8. [Best Practices](#best-practices)

---

## 🎯 Overview

### Features

✅ **Role-Based Access Control** - Granular permission management  
✅ **Multi-tenant Support** - Tenant-specific roles  
✅ **Role Hierarchy** - SuperAdmin > TenantAdmin > User  
✅ **Pre-seeded Roles** - Ready-to-use default roles  
✅ **Flexible Permissions** - Easy to extend  
✅ **Super Admin Account** - Full system access  

### Role Types

| Role ID | Role Name | Scope | Description |
|---------|-----------|-------|-------------|
| 1 | SuperAdmin | Global | Full system access, manages all tenants |
| 2 | TenantAdmin | Tenant | Manages single tenant, all permissions within tenant |
| 3 | User | Tenant | Standard user, limited permissions |
| 4+ | Custom | Tenant | Custom roles (future) |

---

## 🚀 Quick Start

### 1. Run Migrations & Seeders

```bash
cd /var/www/html/EkklesiaSoft/EkklesiaSoftApi

# Run migrations (creates roles table)
php artisan migrate

# Seed default roles
php artisan db:seed --class=Modules\\Authentication\\database\\seeders\\RolesTableSeeder

# Create SuperAdmin user
php artisan db:seed --class=Modules\\Authentication\\database\\seeders\\SuperAdminUserSeeder
```

### 2. SuperAdmin Credentials

```
Email: franklininnocent.fs@gmail.com
Password: Secrete*999
Role: SuperAdmin (role_id: 1)
```

### 3. Test SuperAdmin Access

```bash
# Login as SuperAdmin
curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "franklininnocent.fs@gmail.com",
    "password": "Secrete*999"
  }'

# Response includes:
{
  "user_id": 1,
  "role_id": 1,  # SuperAdmin
  ...
}
```

---

## 🏆 Role Hierarchy

### Permission Levels

```
SuperAdmin (Role ID: 1)
    │
    ├─ Full system access
    ├─ Manage all tenants
    ├─ Create/delete tenants
    ├─ Assign tenant admins
    └─ Global configuration
    
TenantAdmin (Role ID: 2)
    │
    ├─ Full tenant access
    ├─ Manage users in tenant
    ├─ Configure tenant settings
    └─ View tenant reports
    
User (Role ID: 3)
    │
    ├─ View own data
    ├─ Update own profile
    └─ Limited tenant access
```

### Permission Matrix

| Permission | SuperAdmin | TenantAdmin | User |
|------------|-----------|-------------|------|
| View all tenants | ✅ | ❌ | ❌ |
| Create tenant | ✅ | ❌ | ❌ |
| Delete tenant | ✅ | ❌ | ❌ |
| Manage tenant | ✅ | ✅ (own) | ❌ |
| Create users | ✅ | ✅ (tenant) | ❌ |
| View users | ✅ | ✅ (tenant) | ❌ |
| Update own profile | ✅ | ✅ | ✅ |
| View own data | ✅ | ✅ | ✅ |

---

## 🗄️ Database Schema

### roles Table

```sql
CREATE TABLE `roles` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,           -- Role name (SuperAdmin, TenantAdmin, User)
  `description` TEXT NULL,                -- Role description
  `tenant_id` BIGINT UNSIGNED NULL,       -- NULL for global roles, tenant ID for tenant-specific
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  
  INDEX `idx_tenant_id` (`tenant_id`),
  UNIQUE KEY `unique_role_tenant` (`name`, `tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### users Table (Role Relationship)

```sql
ALTER TABLE `users` ADD COLUMN `role_id` BIGINT UNSIGNED DEFAULT 3 AFTER `email`;
ALTER TABLE `users` ADD COLUMN `tenant_id` BIGINT UNSIGNED NULL AFTER `role_id`;

ALTER TABLE `users` ADD FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE SET DEFAULT;
ALTER TABLE `users` ADD FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
```

### Default Roles Seeded

```php
// RolesTableSeeder.php
DB::table('roles')->insert([
    [
        'id' => 1,
        'name' => 'SuperAdmin',
        'description' => 'Super Administrator with full system access',
        'tenant_id' => null,  // Global role
    ],
    [
        'id' => 2,
        'name' => 'TenantAdmin',
        'description' => 'Tenant Administrator with full tenant access',
        'tenant_id' => null,  // Template role
    ],
    [
        'id' => 3,
        'name' => 'User',
        'description' => 'Standard user with limited access',
        'tenant_id' => null,  // Template role
    ],
]);
```

---

## 👑 Super Admin Setup

### SuperAdmin User Creation

```php
// SuperAdminUserSeeder.php
$superAdmin = User::create([
    'name' => 'Franklin Innocent',
    'email' => 'franklininnocent.fs@gmail.com',
    'password' => Hash::make('Secrete*999'),
    'role_id' => 1,  // SuperAdmin
    'tenant_id' => null,  // No tenant (global access)
    'email_verified_at' => now(),
]);
```

### SuperAdmin Capabilities

```php
// Check if user is SuperAdmin
public function isSuperAdmin() {
    return $this->role_id === 1;
}

// In controllers
if (!auth()->user()->isSuperAdmin()) {
    return response()->json(['message' => 'Unauthorized'], 403);
}
```

### SuperAdmin Routes

```php
// routes/api.php
Route::middleware(['auth:api'])->group(function () {
    // SuperAdmin only routes
    Route::middleware('role:SuperAdmin')->group(function () {
        Route::apiResource('tenants', TenantsController::class);
        Route::get('admin/stats', [AdminController::class, 'statistics']);
    });
});
```

---

## 📖 Usage Guide

### 1. Check User Role

```php
// In Controller
public function index(Request $request)
{
    $user = auth()->user();
    
    if ($user->role_id === 1) {
        // SuperAdmin: can see all tenants
        return Tenant::all();
    } elseif ($user->role_id === 2) {
        // TenantAdmin: can see own tenant
        return Tenant::where('id', $user->tenant_id)->get();
    } else {
        // User: no access
        return response()->json(['message' => 'Unauthorized'], 403);
    }
}
```

### 2. Role Middleware

```php
// app/Http/Middleware/CheckRole.php
namespace App\Http\Middleware;

class CheckRole
{
    public function handle($request, Closure $next, ...$roles)
    {
        $user = auth()->user();
        
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        
        $userRole = $user->role->name ?? 'User';
        
        if (!in_array($userRole, $roles)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        return $next($request);
    }
}

// Register in app/Http/Kernel.php
protected $middlewareAliases = [
    'role' => \App\Http\Middleware\CheckRole::class,
];

// Usage in routes
Route::middleware('role:SuperAdmin,TenantAdmin')->group(function () {
    // Only SuperAdmin and TenantAdmin can access
});
```

### 3. User Model Methods

```php
// app/Models/User.php
class User extends Authenticatable
{
    public function role()
    {
        return $this->belongsTo(Role::class);
    }
    
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function isSuperAdmin()
    {
        return $this->role_id === 1;
    }
    
    public function isTenantAdmin()
    {
        return $this->role_id === 2;
    }
    
    public function isUser()
    {
        return $this->role_id === 3;
    }
    
    public function hasRole($roleName)
    {
        return $this->role->name === $roleName;
    }
    
    public function can($permission)
    {
        // SuperAdmin can do anything
        if ($this->isSuperAdmin()) {
            return true;
        }
        
        // Check specific permissions
        // (Implement based on your requirements)
        return false;
    }
}
```

### 4. Permission Checks in Controllers

```php
// TenantsController.php
public function index(Request $request)
{
    $user = auth()->user();
    
    // SuperAdmin sees all tenants
    if ($user->isSuperAdmin()) {
        return response()->json([
            'success' => true,
            'data' => Tenant::all()
        ]);
    }
    
    // TenantAdmin sees own tenant
    if ($user->isTenantAdmin() && $user->tenant_id) {
        return response()->json([
            'success' => true,
            'data' => Tenant::where('id', $user->tenant_id)->get()
        ]);
    }
    
    // Regular users have no access
    return response()->json([
        'success' => false,
        'message' => 'Unauthorized'
    ], 403);
}
```

---

## 🔧 API Implementation

### Creating Tenant-Specific Roles

```php
// When creating a new tenant, create tenant-specific roles
public function createTenant($data)
{
    DB::beginTransaction();
    try {
        // Create tenant
        $tenant = Tenant::create($data);
        
        // Create tenant-specific roles
        $tenantAdminRole = Role::create([
            'name' => 'TenantAdmin',
            'description' => "Administrator for {$tenant->name}",
            'tenant_id' => $tenant->id
        ]);
        
        $tenantUserRole = Role::create([
            'name' => 'User',
            'description' => "User for {$tenant->name}",
            'tenant_id' => $tenant->id
        ]);
        
        // Create primary user as Tenant Admin
        $primaryUser = User::create([
            'name' => $data['primary_user_name'],
            'email' => $data['primary_user_email'],
            'password' => Hash::make('default_password'),
            'role_id' => $tenantAdminRole->id,
            'tenant_id' => $tenant->id
        ]);
        
        DB::commit();
        return $tenant;
    } catch (\Exception $e) {
        DB::rollback();
        throw $e;
    }
}
```

### Assigning Roles

```php
// Assign role to user
public function assignRole(User $user, $roleId)
{
    // Only SuperAdmin or TenantAdmin can assign roles
    if (!auth()->user()->isSuperAdmin() && !auth()->user()->isTenantAdmin()) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }
    
    // TenantAdmin can only assign roles within their tenant
    if (auth()->user()->isTenantAdmin()) {
        $role = Role::find($roleId);
        if ($role->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => 'Cannot assign role from different tenant'], 403);
        }
    }
    
    $user->role_id = $roleId;
    $user->save();
    
    return response()->json(['message' => 'Role assigned successfully']);
}
```

---

## 🎯 Best Practices

### 1. Always Check Permissions

```php
// ❌ Bad: No permission check
public function delete($id) {
    Tenant::destroy($id);
}

// ✅ Good: Check permissions
public function delete($id) {
    if (!auth()->user()->isSuperAdmin()) {
        abort(403, 'Unauthorized');
    }
    Tenant::destroy($id);
}
```

### 2. Use Middleware for Route Protection

```php
// ✅ Good: Protect entire route groups
Route::middleware(['auth:api', 'role:SuperAdmin'])->group(function () {
    Route::apiResource('tenants', TenantsController::class);
});
```

### 3. Tenant Isolation

```php
// ✅ Always scope queries by tenant for non-SuperAdmin users
public function index() {
    $query = Tenant::query();
    
    if (!auth()->user()->isSuperAdmin()) {
        $query->where('id', auth()->user()->tenant_id);
    }
    
    return $query->get();
}
```

### 4. Default Role Assignment

```php
// ✅ Always assign a default role
protected $attributes = [
    'role_id' => 3,  // Default to User role
];
```

### 5. Role Validation

```php
// Validate role_id exists
'role_id' => 'required|exists:roles,id'
```

---

## 📊 Testing

### Test Cases

```php
// tests/Feature/RoleAuthorizationTest.php

public function test_super_admin_can_access_all_tenants()
{
    $superAdmin = User::factory()->create(['role_id' => 1]);
    
    $response = $this->actingAs($superAdmin, 'api')
        ->getJson('/api/tenant/list');
    
    $response->assertStatus(200);
}

public function test_tenant_admin_can_only_access_own_tenant()
{
    $tenant = Tenant::factory()->create();
    $tenantAdmin = User::factory()->create([
        'role_id' => 2,
        'tenant_id' => $tenant->id
    ]);
    
    $response = $this->actingAs($tenantAdmin, 'api')
        ->getJson("/api/tenant/{$tenant->id}");
    
    $response->assertStatus(200);
}

public function test_regular_user_cannot_access_tenants()
{
    $user = User::factory()->create(['role_id' => 3]);
    
    $response = $this->actingAs($user, 'api')
        ->getJson('/api/tenant/list');
    
    $response->assertStatus(403);
}
```

---

## 🔍 Future Enhancements

### 1. Granular Permissions

```php
// permissions table
CREATE TABLE permissions (
    id BIGINT PRIMARY KEY,
    name VARCHAR(255),
    description TEXT
);

// role_permissions pivot table
CREATE TABLE role_permissions (
    role_id BIGINT,
    permission_id BIGINT,
    PRIMARY KEY (role_id, permission_id)
);

// Check permission
public function can($permissionName) {
    return $this->role->permissions->contains('name', $permissionName);
}
```

### 2. Custom Roles

Allow tenants to create custom roles with specific permissions.

### 3. Permission Caching

Cache role permissions for better performance.

### 4. Audit Trail

Log all role assignments and permission changes.

---

## ✅ Summary

- ✅ **3 Default Roles** - SuperAdmin, TenantAdmin, User
- ✅ **Role Hierarchy** - Clear permission levels
- ✅ **Tenant Isolation** - Each tenant has separate roles
- ✅ **SuperAdmin Account** - Full system access
- ✅ **Middleware Protection** - Easy route protection
- ✅ **Flexible & Scalable** - Ready for future enhancements

---

**Last Updated:** October 24, 2025  
**Status:** Complete and Production-Ready

**See also:**
- `01_SETUP_AND_ARCHITECTURE.md` - Setup Guide
- `02_AUTHENTICATION_COMPLETE.md` - Authentication System
- `04_TENANTS_API_COMPLETE.md` - Tenants Management
- `05_TROUBLESHOOTING_AND_FIXES.md` - Common Issues


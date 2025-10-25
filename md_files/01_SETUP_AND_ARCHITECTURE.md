# 🏗️ Setup, Architecture & Module Guide

**EkklesiaSoft API** - Complete Setup and Architecture Documentation

---

## 📚 Table of Contents

1. [Quick Start](#quick-start)
2. [Complete Setup](#complete-setup)
3. [Module Architecture](#module-architecture)
4. [Module Structure](#module-structure)
5. [Creating New Modules](#creating-new-modules)
6. [Best Practices](#best-practices)
7. [Database Management](#database-management)
8. [Security & Performance](#security--performance)

---

## 🚀 Quick Start

**Get up and running in 5 minutes!**

### Prerequisites

- ✅ PHP 8.2+
- ✅ MySQL/MariaDB
- ✅ Composer installed
- ✅ Laravel 12
- ✅ Laravel Passport installed

### Quick Setup Commands

```bash
cd /var/www/html/EkklesiaSoft/EkklesiaSoftApi

# Run migrations
php artisan migrate

# Run seeders
php artisan db:seed

# Fix Passport keys (if needed)
php artisan passport:install --force
chmod 600 storage/oauth-private.key
chmod 600 storage/oauth-public.key

# Start server
php artisan serve
```

### Default Credentials

```
Email: franklininnocent.fs@gmail.com
Password: Secrete*999
Role: SuperAdmin
```

### Test API

```bash
# Login
curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "franklininnocent.fs@gmail.com",
    "password": "Secrete*999"
  }'

# Expected Response:
{
  "access_token": "eyJ0eXAiOiJKV1Q...",
  "refresh_token": "...",
  "expiry_time": "2025-10-24 15:18:47",
  "user_id": 1,
  "role_id": 1,
  "token_type": "Bearer",
  "message": "Login successful"
}
```

---

## ✅ Complete Setup

### 1. Module Reorganization

All features have been organized into nWidart modules:

```
Modules/
├── Authentication/          ✅ User Management, Login, Register, Tokens
├── Tenants/                 ✅ Tenant CRUD, Multi-tenancy
└── (Future modules)         📋 As needed
```

### 2. Authentication Module Structure

```
Modules/Authentication/
├── database/
│   ├── migrations/
│   │   ├── 2025_10_24_071500_create_roles_table.php
│   │   └── 2025_10_24_071501_update_users_table_add_role_and_tenant.php
│   └── seeders/
│       ├── AuthenticationDatabaseSeeder.php
│       ├── RolesTableSeeder.php
│       └── SuperAdminUserSeeder.php
│
├── config/
│   └── authentication.php
│
├── Models/
│   ├── User.php
│   └── Role.php
│
├── Http/Controllers/
│   └── AuthenticationController.php
│
└── routes/
    └── api.php
```

### 3. Tenants Module Structure

```
Modules/Tenants/
├── database/
│   └── migrations/
│       ├── 2025_10_24_080000_create_tenants_table.php
│       └── 2025_10_24_124409_update_tenants_table_for_complete_api.php
│
├── app/
│   ├── Models/
│   │   └── Tenant.php
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── TenantsController.php
│   │   └── Requests/
│   │       ├── StoreTenantRequest.php
│   │       └── UpdateTenantRequest.php
│   │
│   └── Services/
│       └── FileUploadService.php
│
└── routes/
    └── api.php
```

### 4. Database Setup

```bash
# Run all module migrations
php artisan migrate

# Run module seeders
php artisan db:seed --class=Modules\\Authentication\\database\\seeders\\AuthenticationDatabaseSeeder

# Or run all seeders
php artisan db:seed
```

### 5. Passport Configuration

```bash
# Install Passport
php artisan passport:install --force

# Create password grant client
php artisan passport:client --password

# Fix permissions
chmod 600 storage/oauth-private.key
chmod 600 storage/oauth-public.key
```

---

## 🏗️ Module Architecture

### Why nWidart Modules?

EkklesiaSoft uses **nWidart/laravel-modules** for:

#### **1. Separation of Concerns**
Each module handles ONE domain (e.g., Authentication, Tenants, Billing)

#### **2. Scalability**
- ✅ Add new features without touching existing code
- ✅ Each module can have its own team
- ✅ Independent versioning possible

#### **3. Maintainability**
- ✅ Easy to locate code
- ✅ Clear boundaries between features
- ✅ Reduced merge conflicts

#### **4. Reusability**
- ✅ Modules can be reused across projects
- ✅ Easy to extract into packages

#### **5. Testing**
- ✅ Test modules in isolation
- ✅ Faster test execution
- ✅ Clear test organization

### Current Modules

| Module | Purpose | Status |
|--------|---------|--------|
| **Authentication** | User management, Login, Register, JWT Tokens | ✅ Complete |
| **Tenants** | Multi-tenancy, Tenant CRUD, Isolation | ✅ Complete |
| **Roles** | Role & Permission Management (future) | 📋 Planned |
| **Billing** | Subscriptions, Payments (future) | 📋 Planned |
| **Reports** | Analytics, Dashboards (future) | 📋 Planned |

---

## 📦 Module Structure

### Standard nWidart Module Layout

```
Modules/{ModuleName}/
│
├── app/                                   # Application code
│   ├── Http/
│   │   ├── Controllers/                   # API Controllers
│   │   ├── Requests/                      # Form Requests (validation)
│   │   └── Middleware/                    # Module-specific middleware
│   │
│   ├── Models/                            # Eloquent models
│   ├── Services/                          # Business logic
│   ├── Repositories/                      # Data access layer (optional)
│   └── Providers/
│       ├── {Module}ServiceProvider.php
│       └── RouteServiceProvider.php
│
├── config/                                # Module configuration
│   └── config.php
│
├── database/
│   ├── migrations/                        # Database migrations
│   ├── seeders/                           # Database seeders
│   └── factories/                         # Model factories
│
├── resources/
│   ├── views/                             # Blade templates (if needed)
│   └── lang/                              # Translations
│
├── routes/
│   ├── api.php                            # API routes
│   └── web.php                            # Web routes
│
├── tests/
│   ├── Feature/                           # Feature tests
│   └── Unit/                              # Unit tests
│
├── composer.json                          # Module dependencies
├── module.json                            # Module metadata
└── README.md                              # Module documentation
```

### Module File Purposes

| File/Folder | Purpose |
|-------------|---------|
| `app/Http/Controllers/` | Handle HTTP requests, call services |
| `app/Models/` | Database models (Eloquent) |
| `app/Services/` | Business logic (keep controllers thin) |
| `app/Http/Requests/` | Validation rules |
| `database/migrations/` | Database schema changes |
| `database/seeders/` | Test/default data |
| `routes/api.php` | API endpoints |
| `config/config.php` | Module settings |
| `tests/` | Automated tests |

---

## ⚡ Creating New Modules

### Method 1: Using Artisan Command

```bash
# Create a new module
php artisan module:make ModuleName

# Create module with specific options
php artisan module:make Billing --plain  # No default files

# Enable module
php artisan module:enable Billing

# Disable module
php artisan module:disable Billing
```

### Method 2: Generate Module Components

```bash
# Create a controller
php artisan module:make-controller ProductsController Billing

# Create a model
php artisan module:make-model Product Billing

# Create a migration
php artisan module:make-migration create_products_table Billing

# Create a seeder
php artisan module:make-seeder ProductsSeeder Billing

# Create a request
php artisan module:make-request StoreProductRequest Billing

# Create a service
php artisan module:make-service ProductService Billing
```

### Example: Creating a "Billing" Module

```bash
# Step 1: Create module
php artisan module:make Billing

# Step 2: Create components
php artisan module:make-model Subscription Billing
php artisan module:make-controller SubscriptionsController Billing
php artisan module:make-migration create_subscriptions_table Billing
php artisan module:make-request StoreSubscriptionRequest Billing

# Step 3: Define routes
# Edit Modules/Billing/routes/api.php

# Step 4: Run migration
php artisan migrate

# Step 5: Test
php artisan test Modules/Billing/tests
```

---

## 🎯 Best Practices

### 1. Keep Controllers Thin

**❌ Bad:**
```php
public function store(Request $request) {
    // 100 lines of validation, business logic, database queries
}
```

**✅ Good:**
```php
public function store(StoreUserRequest $request) {
    $user = $this->userService->createUser($request->validated());
    return response()->json($user, 201);
}
```

### 2. Use Form Requests for Validation

```php
// app/Http/Requests/StoreTenantRequest.php
public function rules() {
    return [
        'tenant_name' => 'required|string|max:255',
        'primary_user_email' => 'required|email|unique:users,email',
        // ... more rules
    ];
}
```

### 3. Use Services for Business Logic

```php
// app/Services/TenantService.php
class TenantService {
    public function createTenant(array $data) {
        DB::beginTransaction();
        try {
            $tenant = Tenant::create($data);
            $this->createPrimaryUser($tenant, $data);
            DB::commit();
            return $tenant;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }
}
```

### 4. Follow Naming Conventions

| Type | Convention | Example |
|------|-----------|---------|
| Controller | Plural + Controller | `TenantsController` |
| Model | Singular | `Tenant` |
| Request | Action + Model + Request | `StoreTenantRequest` |
| Service | Model + Service | `TenantService` |
| Migration | descriptive_name | `create_tenants_table` |

### 5. Module Independence

Each module should be **self-contained**:

- ✅ Own migrations
- ✅ Own seeders
- ✅ Own configuration
- ✅ Own routes
- ✅ Own tests

### 6. API Versioning

```php
// routes/api.php
Route::prefix('v1')->group(function () {
    Route::apiResource('tenants', TenantsController::class);
});
```

---

## 🗄️ Database Management

### Running Migrations

```bash
# Run all migrations
php artisan migrate

# Run specific module migrations
php artisan module:migrate Authentication

# Rollback
php artisan module:migrate-rollback Authentication

# Refresh (rollback + migrate)
php artisan module:migrate-refresh Authentication

# Status
php artisan module:migrate-status
```

### Creating Migrations

```bash
# Create migration
php artisan module:make-migration create_products_table Billing

# With model
php artisan module:make-model Product Billing -m
```

### Migration Best Practices

1. **Always use transactions** (automatic in Laravel)
2. **Make migrations reversible** (implement `down()`)
3. **Don't modify existing migrations** after deployment
4. **Use proper data types**
5. **Add indexes** for foreign keys and searchable columns

---

## 🔐 Security & Performance

### Security Best Practices

#### 1. Authentication & Authorization

```php
// Always protect API routes
Route::middleware('auth:api')->group(function () {
    Route::apiResource('tenants', TenantsController::class);
});

// Check permissions
if (!$user->can('manage-tenants')) {
    abort(403, 'Unauthorized');
}
```

#### 2. Input Validation

```php
// Use Form Requests
public function store(StoreTenantRequest $request) {
    // $request->validated() only contains validated data
}
```

#### 3. SQL Injection Prevention

```php
// ✅ Good: Using Eloquent ORM
$user = User::where('email', $email)->first();

// ✅ Good: Query Builder with bindings
$users = DB::select('select * from users where email = ?', [$email]);

// ❌ Bad: Raw SQL without bindings
$users = DB::select("select * from users where email = '$email'");
```

#### 4. CORS Configuration

```php
// config/cors.php
'allowed_origins' => env('FRONTEND_URL', 'http://localhost:4200'),
```

### Performance Optimization

#### 1. Eager Loading

```php
// ❌ Bad: N+1 Query Problem
$tenants = Tenant::all();
foreach ($tenants as $tenant) {
    echo $tenant->user->name; // Queries for each tenant
}

// ✅ Good: Eager Loading
$tenants = Tenant::with('user')->get();
foreach ($tenants as $tenant) {
    echo $tenant->user->name; // Single query
}
```

#### 2. Caching

```php
// Cache tenant list
$tenants = Cache::remember('active_tenants', 3600, function () {
    return Tenant::where('active', 1)->get();
});
```

#### 3. Database Indexes

```php
// Add indexes in migrations
$table->index('email');
$table->index('tenant_id');
$table->index(['tenant_id', 'active']);
```

#### 4. Pagination

```php
// Always paginate large datasets
return Tenant::paginate(20);
```

---

## 📊 Module Status

| Module | Migrations | Models | Controllers | Services | Tests | Status |
|--------|-----------|--------|-------------|----------|-------|--------|
| Authentication | ✅ | ✅ | ✅ | ✅ | 📋 | Complete |
| Tenants | ✅ | ✅ | ✅ | ✅ | 📋 | Complete |

---

## 🎓 Learning Resources

### Official Documentation

- [Laravel Documentation](https://laravel.com/docs)
- [nWidart Modules](https://nwidart.com/laravel-modules/v6/introduction)
- [Laravel Passport](https://laravel.com/docs/passport)

### Best Practices

- [Laravel Best Practices](https://github.com/alexeymezenin/laravel-best-practices)
- [PHP The Right Way](https://phptherightway.com/)

---

## 📝 Next Steps

1. ✅ **Setup Complete** - You have a working modular API
2. 📋 **Add New Features** - Create modules as needed
3. 📋 **Write Tests** - Add unit and feature tests
4. 📋 **API Documentation** - Consider Swagger/OpenAPI
5. 📋 **Monitoring** - Add logging and error tracking

---

**Last Updated:** October 24, 2025  
**Status:** Complete and Production-Ready

**See also:**
- `02_AUTHENTICATION_COMPLETE.md` - Authentication & Token Documentation
- `03_ROLES_AND_PERMISSIONS_COMPLETE.md` - RBAC System
- `04_TENANTS_API_COMPLETE.md` - Tenants Management
- `05_TROUBLESHOOTING_AND_FIXES.md` - Common Issues & Solutions


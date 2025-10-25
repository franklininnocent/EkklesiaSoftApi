# Authentication Module

**Version**: 1.0.0  
**Author**: EkklesiaSoft Team  
**Purpose**: Complete authentication and authorization system for large-scale SaaS application

---

## 📋 Module Overview

The Authentication module provides a complete, self-contained authentication and role-based authorization system following nWidart module architecture. It includes user management, role hierarchy, multi-tenant support, and secure API authentication using Laravel Passport.

---

## 🏗️ Module Structure

```
Modules/Authentication/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       └── AuthenticationController.php   # API endpoints (login, register, logout, user)
│   └── Providers/
│       ├── AuthenticationServiceProvider.php  # Module service provider
│       ├── EventServiceProvider.php           # Event handlers
│       └── RouteServiceProvider.php           # Route configuration
├── config/
│   └── authentication.php                     # Module configuration
├── database/
│   ├── migrations/
│   │   ├── 2025_10_24_071500_create_roles_table.php
│   │   └── 2025_10_24_071501_update_users_table_add_role_and_tenant.php
│   └── seeders/
│       ├── AuthenticationDatabaseSeeder.php   # Main module seeder
│       ├── RolesTableSeeder.php               # Seeds 4 predefined roles
│       └── SuperAdminUserSeeder.php           # Seeds default Super Admin
├── Models/
│   ├── Role.php                               # Role model with relationships
│   └── User.php                               # User model with authentication
├── routes/
│   ├── api.php                                # API routes
│   └── web.php                                # Web routes (if needed)
├── module.json                                # Module metadata
└── README.md                                  # This file
```

---

## 🎯 Features

### 1. **User Authentication**
- ✅ Registration with email/password
- ✅ Login with JWT tokens (Laravel Passport)
- ✅ Logout with token revocation
- ✅ Get authenticated user information
- ✅ Active status checking
- ✅ Soft deletes support

### 2. **Role-Based Authorization**
- ✅ Four-level role hierarchy (SuperAdmin, EkklesiaAdmin, EkklesiaManager, EkklesiaUser)
- ✅ Role-based access control
- ✅ Active/inactive role status
- ✅ Soft deletes for roles

### 3. **Multi-Tenant Support**
- ✅ Tenant ID tracking
- ✅ Tenant isolation
- ✅ Super Admin global access (no tenant)
- ✅ Tenant-specific user management

### 4. **Security Features**
- ✅ Password hashing (bcrypt)
- ✅ Active status validation
- ✅ Role status validation
- ✅ Token-based API authentication
- ✅ CORS support

---

## 🚀 Installation & Setup

### Step 1: Run Migrations

```bash
# Migrations are automatically loaded from the module
php artisan migrate

# Expected migrations:
# - 2025_10_24_071500_create_roles_table
# - 2025_10_24_071501_update_users_table_add_role_and_tenant
```

### Step 2: Seed Database

```bash
# Seed the entire application (includes Authentication module)
php artisan db:seed

# OR seed only Authentication module
php artisan db:seed --class=Modules\\Authentication\\Database\\Seeders\\AuthenticationDatabaseSeeder
```

### Step 3: Publish Configuration (Optional)

```bash
# Publish module config to root config folder
php artisan vendor:publish --tag=authentication-config

# Config will be available at: config/authentication.php
```

---

## 🔐 Default Credentials

After seeding, you can login with:

```
Email: franklininnocent.fs@gmail.com
Password: Secrete*999
Role: SuperAdmin
```

---

## 📡 API Endpoints

Base URL: `/api/auth`

### Public Endpoints

#### Register User
```http
POST /api/auth/register

Body:
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password123",
  "password_confirmation": "password123",
  "role_id": 4,        // Optional, defaults to EkklesiaUser (4)
  "tenant_id": null    // Optional
}

Response (201):
{
  "user": {
    "id": 2,
    "name": "John Doe",
    "email": "john@example.com",
    "role_id": 4,
    "role_name": "EkklesiaUser",
    "role_level": 4,
    "active": 1
  },
  "access_token": "eyJ0eXAiOiJKV1Qi...",
  "message": "Registration successful"
}
```

#### Login
```http
POST /api/auth/login

Body:
{
  "email": "franklininnocent.fs@gmail.com",
  "password": "Secrete*999"
}

Response (200):
{
  "user": {
    "id": 1,
    "name": "Franklin Innocent F",
    "email": "franklininnocent.fs@gmail.com",
    "role_id": 1,
    "tenant_id": null,
    "active": 1,
    "role_name": "SuperAdmin",
    "role_level": 1,
    "is_super_admin": true,
    "is_admin": true
  },
  "access_token": "eyJ0eXAiOiJKV1Qi...",
  "message": "Login successful"
}
```

### Protected Endpoints (Require Bearer Token)

#### Logout
```http
POST /api/auth/logout

Headers:
Authorization: Bearer {access_token}

Response (200):
{
  "message": "Logged out successfully"
}
```

#### Get Current User
```http
GET /api/auth/user

Headers:
Authorization: Bearer {access_token}

Response (200):
{
  "id": 1,
  "name": "Franklin Innocent F",
  "email": "franklininnocent.fs@gmail.com",
  "role_id": 1,
  "role_name": "SuperAdmin",
  "role_level": 1,
  "is_super_admin": true,
  "is_admin": true
}
```

---

## 🎭 Role Hierarchy

| Level | Role Name | Description | Default Active |
|-------|-----------|-------------|----------------|
| **1** | SuperAdmin | Full system privileges, manages all tenants | ✅ Active |
| **2** | EkklesiaAdmin | Tenant management privileges | ✅ Active |
| **3** | EkklesiaManager | Limited administrative access | ✅ Active |
| **4** | EkklesiaUser | Basic user access | ✅ Active |

---

## 💻 Usage Examples

### Check User Role

```php
use Illuminate\Support\Facades\Auth;

$user = Auth::user();

if ($user->isSuperAdmin()) {
    // Grant full system access
}

if ($user->isAdmin()) {
    // Grant admin access
}

if ($user->isEkklesiaManager()) {
    // Grant manager access
}
```

### Query Users by Role

```php
use Modules\Authentication\Models\User;

// Get all Super Admins
$superAdmins = User::byRole('SuperAdmin')->get();

// Get active users only
$activeUsers = User::active()->get();

// Get users by tenant
$tenantUsers = User::byTenant(1)->get();

// Combine filters
$activeTenantUsers = User::active()
    ->byTenant(1)
    ->byRole('EkklesiaUser')
    ->get();
```

### Manage User Status

```php
$user = User::find(10);

// Deactivate user
$user->deactivate(); // Sets active = 0

// Activate user
$user->activate(); // Sets active = 1

// Check if active
if ($user->isActive()) {
    // Allow access
}
```

### Soft Delete Operations

```php
// Soft delete user
$user->delete(); // Sets deleted_at timestamp

// Restore deleted user
$user->restore(); // Sets deleted_at = NULL

// Get only trashed users
$trashedUsers = User::onlyTrashed()->get();

// Permanently delete
$user->forceDelete();
```

---

## ⚙️ Configuration

Configuration file: `Modules/Authentication/config/authentication.php`

### Key Configuration Options

```php
// Default role for new users
'default_role' => 'EkklesiaUser',
'default_role_id' => 4,

// Security settings
'security' => [
    'check_active_status' => true,
    'check_role_active_status' => true,
    'password_min_length' => 8,
],

// Multi-tenant settings
'multi_tenant' => [
    'enabled' => true,
    'super_admin_has_no_tenant' => true,
],
```

---

## 🗄️ Database Schema

### Roles Table

```sql
CREATE TABLE `roles` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) UNIQUE NOT NULL,
  `description` TEXT NULL,
  `level` INT NOT NULL,
  `active` INT DEFAULT 1,
  `deleted_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL
);
```

### Users Table (Updated)

```sql
ALTER TABLE `users`
  ADD `role_id` BIGINT UNSIGNED NULL,
  ADD `tenant_id` BIGINT UNSIGNED NULL,
  ADD `active` INT DEFAULT 1,
  ADD `deleted_at` TIMESTAMP NULL,
  ADD CONSTRAINT `users_role_id_foreign`
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE SET NULL;
```

---

## 🧪 Testing

```bash
# Test login endpoint
curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"franklininnocent.fs@gmail.com","password":"Secrete*999"}'

# Test with Laravel Tinker
php artisan tinker

>>> $user = Modules\Authentication\Models\User::first();
>>> $user->isSuperAdmin(); // true
>>> $user->role->name; // "SuperAdmin"
```

---

## 🔄 Module Commands

```bash
# Run module migrations
php artisan module:migrate Authentication

# Rollback module migrations
php artisan module:migrate-rollback Authentication

# Seed module
php artisan module:seed Authentication

# Publish module config
php artisan vendor:publish --tag=authentication-config
```

---

## 🚀 Future Enhancements

### Planned Features
1. **Permission System**: Fine-grained permissions per role
2. **Two-Factor Authentication**: SMS/Email OTP support
3. **OAuth Integration**: Social login (Google, Facebook, etc.)
4. **Audit Logs**: Track all authentication events
5. **Password Reset**: Email-based password recovery
6. **Email Verification**: Verify email on registration
7. **Role Management API**: CRUD for roles
8. **User Management API**: CRUD for users

---

## 🏅 Best Practices

### Security
- Always use HTTPS in production
- Rotate JWT secrets regularly
- Implement rate limiting on auth endpoints
- Log failed login attempts
- Use strong password policies

### Performance
- Use database indexing (already implemented)
- Cache role and permission checks
- Implement query scopes for common filters
- Use eager loading for relationships

### Maintenance
- Keep migrations in module
- Version control all changes
- Document custom configurations
- Write tests for critical flows

---

## 📞 Support

For issues or questions:
1. Check Laravel logs: `storage/logs/laravel.log`
2. Verify module is enabled in `modules_statuses.json`
3. Check database connection
4. Review module configuration

---

## 📄 License

Proprietary - EkklesiaSoft  
All rights reserved.

---

**Built with ❤️ following nWidart module architecture for large-scale SaaS applications**


# Tenants Module

**Version**: 1.0.0  
**Purpose**: Multi-tenant management for EkklesiaSoft SaaS application

---

## 📋 Module Overview

The Tenants module provides complete tenant (organization) management for the EkklesiaSoft platform. It handles tenant creation, subscription management, branding, and isolation following nWidart module architecture.

---

## 🎯 Features

### 1. **Tenant CRUD Operations**
- ✅ Create tenants (organizations/churches)
- ✅ List tenants with pagination and filtering
- ✅ View tenant details with statistics
- ✅ Update tenant information
- ✅ Delete tenants (soft delete)
- ✅ Restore deleted tenants
- ✅ Activate/deactivate tenants

### 2. **Subscription Management**
- ✅ Multiple subscription plans (Free, Basic, Premium, Enterprise)
- ✅ User limits per plan
- ✅ Storage limits per plan
- ✅ Trial period management
- ✅ Subscription expiration tracking

### 3. **Tenant Branding**
- ✅ Custom logo upload
- ✅ Custom color schemes (primary/secondary)
- ✅ Custom domain support (optional)
- ✅ Tenant-specific settings

### 4. **Tenant Isolation**
- ✅ Data isolation between tenants
- ✅ Tenant-specific roles
- ✅ Middleware for tenant context
- ✅ SuperAdmin bypass for cross-tenant access

---

## 🚀 API Endpoints

Base URL: `/api/tenants`  
**All endpoints require authentication (`auth:api` middleware)**

### List All Tenants

```http
GET /api/tenants

Query Parameters:
- active: Filter by active status (0 or 1)
- plan: Filter by subscription plan (free, basic, premium, enterprise)
- search: Search by name, email, or slug
- per_page: Items per page (default: 15)

Authorization: Bearer {token}
Required Role: SuperAdmin or EkklesiaAdmin

Response (200):
{
  "data": [
    {
      "id": 1,
      "name": "First Baptist Church",
      "slug": "first-baptist-church",
      "email": "admin@firstbaptist.com",
      "plan": "premium",
      "active": 1,
      ...
    }
  ],
  "meta": { ... },
  "links": { ... }
}
```

### Get Tenant Details

```http
GET /api/tenants/{id}

Authorization: Bearer {token}
Required Role: SuperAdmin or EkklesiaAdmin

Response (200):
{
  "tenant": {
    "id": 1,
    "name": "First Baptist Church",
    ...
  },
  "stats": {
    "total_users": 45,
    "active_users": 42,
    "remaining_slots": 55,
    "has_active_subscription": true,
    "is_in_trial": false
  }
}
```

### Create Tenant

```http
POST /api/tenants

Body:
{
  "name": "Grace Community Church",
  "slug": "grace-community",  // Optional, auto-generated from name
  "email": "admin@gracechurch.org",
  "phone": "(555) 123-4567",
  "address": "123 Main St",
  "city": "Austin",
  "state": "TX",
  "country": "USA",
  "postal_code": "73301",
  "plan": "basic",
  "max_users": 50,
  "max_storage_mb": 1000,
  "trial_ends_at": "2025-11-24",
  "primary_color": "#3B82F6",
  "secondary_color": "#10B981"
}

Authorization: Bearer {token}
Required Role: SuperAdmin or EkklesiaAdmin

Response (201):
{
  "message": "Tenant created successfully",
  "tenant": { ... }
}
```

### Update Tenant

```http
PUT /api/tenants/{id}
PATCH /api/tenants/{id}

Body: (all fields optional)
{
  "name": "Updated Church Name",
  "active": 1,
  "plan": "premium",
  ...
}

Authorization: Bearer {token}
Required Role: SuperAdmin or EkklesiaAdmin

Response (200):
{
  "message": "Tenant updated successfully",
  "tenant": { ... }
}
```

### Delete Tenant

```http
DELETE /api/tenants/{id}

Authorization: Bearer {token}
Required Role: SuperAdmin or EkklesiaAdmin

Response (200):
{
  "message": "Tenant deleted successfully"
}

Error (422): If tenant has users
{
  "message": "Cannot delete tenant with existing users..."
}
```

### Restore Tenant

```http
POST /api/tenants/{id}/restore

Authorization: Bearer {token}
Required Role: SuperAdmin or EkklesiaAdmin

Response (200):
{
  "message": "Tenant restored successfully",
  "tenant": { ... }
}
```

### Activate Tenant

```http
POST /api/tenants/{id}/activate

Authorization: Bearer {token}
Required Role: SuperAdmin or EkklesiaAdmin

Response (200):
{
  "message": "Tenant activated successfully",
  "tenant": { ... }
}
```

### Deactivate Tenant

```http
POST /api/tenants/{id}/deactivate

Authorization: Bearer {token}
Required Role: SuperAdmin or EkklesiaAdmin

Response (200):
{
  "message": "Tenant deactivated successfully",
  "tenant": { ... }
}
```

### Get Tenant Statistics

```http
GET /api/tenants/statistics

Authorization: Bearer {token}
Required Role: SuperAdmin or EkklesiaAdmin

Response (200):
{
  "total_tenants": 150,
  "active_tenants": 145,
  "inactive_tenants": 5,
  "tenants_by_plan": {
    "free": 80,
    "basic": 40,
    "premium": 20,
    "enterprise": 10
  },
  "in_trial": 25,
  "subscribed": 125,
  "recent_tenants": [ ... ]
}
```

---

## 💾 Database Schema

### Tenants Table

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT | Primary key |
| name | VARCHAR | Organization/Church name |
| slug | VARCHAR | URL-friendly identifier (unique) |
| domain | VARCHAR | Custom domain (optional, unique) |
| email | VARCHAR | Primary contact email |
| phone | VARCHAR | Primary contact phone |
| address | TEXT | Street address |
| city | VARCHAR | City |
| state | VARCHAR | State/Province |
| country | VARCHAR | Country (default: USA) |
| postal_code | VARCHAR | ZIP/Postal code |
| plan | VARCHAR | Subscription plan |
| max_users | INT | Maximum allowed users |
| max_storage_mb | INT | Storage limit in MB |
| trial_ends_at | TIMESTAMP | Trial period end date |
| subscription_ends_at | TIMESTAMP | Subscription end date |
| active | INT | Status (1=Active, 0=Inactive) |
| settings | JSON | Tenant-specific settings |
| features | JSON | Enabled features array |
| logo_url | VARCHAR | Logo URL |
| primary_color | VARCHAR | Primary brand color (hex) |
| secondary_color | VARCHAR | Secondary brand color (hex) |
| created_by | BIGINT | Creator user ID |
| updated_by | BIGINT | Last updater user ID |
| deleted_at | TIMESTAMP | Soft delete timestamp |
| created_at | TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | Update timestamp |

**Indexes:**
- `slug` (unique)
- `domain` (unique)
- `active, deleted_at` (composite)
- `plan`
- `created_at`

---

## 📊 Subscription Plans

| Plan | Max Users | Storage (MB) | Features | Price |
|------|-----------|-------------|----------|-------|
| **Free** | 10 | 100 | Events | $0 |
| **Basic** | 50 | 1,000 | Events, Donations | $29.99 |
| **Premium** | 100 | 5,000 | Events, Donations, Groups, Messaging | $99.99 |
| **Enterprise** | Unlimited | 50,000 | All features + Custom Branding, API, Support | $299.99 |

---

## 🔧 Usage Examples

### Check Tenant Subscription Status

```php
$tenant = Tenant::find(1);

if ($tenant->hasActiveSubscription()) {
    // Allow access
}

if ($tenant->isInTrial()) {
    // Show trial banner
}

if ($tenant->hasExceededUserLimit()) {
    // Prevent new user creation
}
```

### Get Tenant Settings

```php
$tenant = Tenant::find(1);

$timezone = $tenant->getSetting('timezone', 'UTC');
$language = $tenant->getSetting('language', 'en');
```

### Set Tenant Settings

```php
$tenant = Tenant::find(1);

$tenant->setSetting('timezone', 'America/New_York');
$tenant->setSetting('notification_email', 'notifications@church.org');
```

### Check Tenant Features

```php
$tenant = Tenant::find(1);

if ($tenant->hasFeature('messaging')) {
    // Enable messaging module
}
```

### Query Tenants

```php
// Get active premium tenants
$premiumTenants = Tenant::active()
    ->byPlan('premium')
    ->get();

// Get tenants in trial
$trialTenants = Tenant::inTrial()->get();

// Get subscribed tenants
$subscribedTenants = Tenant::subscribed()->get();
```

---

## 🛡️ Authorization

- **SuperAdmin**: Full access to all tenant operations
- **EkklesiaAdmin**: Can manage all tenants  
- **EkklesiaManager**: Read-only access to tenants (cannot create/update/delete)
- **EkklesiaUser**: No access to tenant management

---

## 🔐 Middleware

### SetTenantContext

Automatically sets the current tenant context for authenticated users.

```php
// Apply in routes
Route::middleware(['auth:api', SetTenantContext::class])->group(function () {
    // Routes here will have tenant context
});

// Access current tenant ID
$tenantId = config('app.current_tenant_id');
$tenantId = request()->get('current_tenant_id');
```

### EnsureTenantAccess

Ensures users can only access their own tenant's data (SuperAdmin excluded).

```php
Route::middleware(['auth:api', EnsureTenantAccess::class])->group(function () {
    // Tenant-isolated routes
});
```

---

## 📝 Configuration

Configuration file: `Modules/Tenants/config/tenants.php`

```php
// Get config values
$maxTenants = config('tenants.limits.max_tenants');
$trialDays = config('tenants.trial_days');
$allowCustomDomains = config('tenants.domain.allow_custom_domains');
```

---

## 🧪 Testing

```bash
# Test tenant creation
curl -X POST http://127.0.0.1:8000/api/tenants \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"name":"Test Church","email":"test@church.com","plan":"free"}'

# Test tenant list
curl -X GET http://127.0.0.1:8000/api/tenants \
  -H "Authorization: Bearer {token}"
```

---

## 📖 Related Modules

- **Authentication**: User authentication and role management
- **Roles**: Role management including tenant-specific roles

---

## 🚀 Future Enhancements

- [ ] Tenant-specific database connections
- [ ] Tenant analytics dashboard
- [ ] Automated billing integration
- [ ] Tenant onboarding wizard
- [ ] Tenant data export
- [ ] Tenant migration tools

---

**Built with ❤️ for large-scale multi-tenant SaaS**


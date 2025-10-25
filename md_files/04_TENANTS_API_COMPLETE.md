# 🏢 Tenants API - Complete Documentation

**EkklesiaSoft API** - Multi-Tenancy Management System

---

## 📚 Quick Reference

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/tenant/list` | GET | Required | List all tenants |
| `/api/tenant/{id}` | GET | Required | Get single tenant |
| `/api/tenant` | POST | Required | Create tenant |
| `/api/tenant/{id}` | PUT/PATCH | Required | Update tenant |
| `/api/tenant/{id}` | DELETE | Required | Delete tenant |
| `/api/tenant/{id}/logo` | POST | Required | Upload logo |
| `/api/tenant/statistics` | GET | Required | Get statistics |

---

## 🔐 Authorization

**Only SuperAdmin (role_id = 1) can manage tenants.**

```bash
# All requests must include Bearer token
Authorization: Bearer {access_token}
```

---

## 📡 API Endpoints

### 1. List All Tenants

```http
GET /api/tenant/list
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "First Church",
      "slug": "first-church",
      "primary_user_email": "john@first.com",
      "active": 1,
      "plan": "premium",
      "logo_url": "tenants/logos/logo123.png"
    }
  ]
}
```

### 2. Get Single Tenant

```http
GET /api/tenant/{id}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "First Church",
    "slug": "first-church",
    "primary_user_name": "John Doe",
    "primary_user_email": "john@first.com",
    "primary_contact_number": "+1234567890",
    "official_address": {
      "line1": "123 Main St",
      "line2": "",
      "district": "Downtown",
      "state_province": "California",
      "country": "USA",
      "pin_zip_code": "90001"
    },
    "logo_url": "tenants/logos/logo_20251024_abc123.png",
    "plan": "premium",
    "active": 1
  }
}
```

### 3. Create Tenant

```http
POST /api/tenant
Content-Type: multipart/form-data
```

**Required Fields:**
- `tenant_name` - Organization name
- `primary_user_name` - Primary contact name
- `primary_user_email` - Primary email (unique)
- `primary_contact_number` - Phone number
- `official_address[line1]` - Address line 1
- `official_address[district]` - District/City
- `official_address[state_province]` - State/Province
- `official_address[country]` - Country
- `official_address[pin_zip_code]` - ZIP code

**Optional Fields:**
- `tenant_logo` - Logo file (max 5MB)
- `secondary_user_name`, `secondary_user_email`, `secondary_contact_number`
- `official_address2[...]` - Secondary address
- `plan` - Subscription plan
- `max_users`, `max_storage_mb`

**Response:** `201 Created`

### 4. Update Tenant

```http
PUT /api/tenant/{id}
```

**All fields are optional for updates**

**Response:** `200 OK`

### 5. Delete Tenant

```http
DELETE /api/tenant/{id}
```

**Response:**
```json
{
  "success": true,
  "message": "Tenant deleted successfully"
}
```

### 6. Upload Logo

```http
POST /api/tenant/{id}/logo
Content-Type: multipart/form-data
```

**Request:**
```
logo: (file) - Image file (JPEG, PNG, GIF, WebP, max 5MB)
```

**Response:**
```json
{
  "success": true,
  "message": "Logo uploaded successfully",
  "data": {
    "logo_url": "tenants/logos/logo_20251024_abc123.png"
  }
}
```

### 7. Get Statistics

```http
GET /api/tenant/statistics
```

**Response:**
```json
{
  "success": true,
  "data": {
    "total_tenants": 10,
    "active_tenants": 8,
    "inactive_tenants": 2,
    "total_users": 150,
    "tenants_by_plan": {
      "free": 5,
      "premium": 3,
      "enterprise": 2
    }
  }
}
```

---

## 🗄️ Database Schema

```sql
CREATE TABLE tenants (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE,
    domain VARCHAR(255) UNIQUE NULL,
    primary_user_name VARCHAR(255) NOT NULL,
    primary_user_email VARCHAR(255) UNIQUE NOT NULL,
    primary_contact_number VARCHAR(20) NOT NULL,
    secondary_user_name VARCHAR(255) NULL,
    secondary_user_email VARCHAR(255) UNIQUE NULL,
    secondary_contact_number VARCHAR(20) NULL,
    official_address JSON NOT NULL,
    official_address2 JSON NULL,
    logo_url VARCHAR(255) NULL,
    primary_color VARCHAR(7) DEFAULT '#3B82F6',
    secondary_color VARCHAR(7) DEFAULT '#10B981',
    plan ENUM('free','basic','premium','enterprise') DEFAULT 'free',
    max_users INT DEFAULT 10,
    max_storage_mb INT DEFAULT 100,
    trial_ends_at DATETIME NULL,
    subscription_ends_at DATETIME NULL,
    settings JSON NULL,
    features JSON NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP NULL
);
```

---

## ✅ Validation Rules

### Create Tenant

```php
'tenant_name' => 'required|string|max:255',
'slug' => 'nullable|string|max:255|unique:tenants,slug',
'primary_user_email' => 'required|email|unique:users,email',
'official_address' => 'required|array',
'official_address.line1' => 'required|string|max:255',
'official_address.country' => 'required|string|max:100',
'tenant_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
'plan' => 'nullable|in:free,basic,premium,enterprise',
```

---

## 🧪 Testing Examples

### Test Create Tenant

```bash
curl -X POST http://127.0.0.1:8000/api/tenant \
  -H "Authorization: Bearer {token}" \
  -F "tenant_name=Test Church" \
  -F "primary_user_name=John Doe" \
  -F "primary_user_email=john.unique@test.com" \
  -F "primary_contact_number=555-1234" \
  -F "official_address[line1]=123 Main St" \
  -F "official_address[district]=Downtown" \
  -F "official_address[state_province]=California" \
  -F "official_address[country]=USA" \
  -F "official_address[pin_zip_code]=90210"
```

---

## 📝 Implementation Files

| File | Purpose |
|------|---------|
| `Modules/Tenants/app/Models/Tenant.php` | Eloquent model |
| `Modules/Tenants/app/Http/Controllers/TenantsController.php` | API controller |
| `Modules/Tenants/app/Http/Requests/StoreTenantRequest.php` | Validation |
| `Modules/Tenants/app/Services/FileUploadService.php` | Logo upload |
| `Modules/Tenants/routes/api.php` | Route definitions |
| `Modules/Tenants/database/migrations/` | Database migrations |

---

**Last Updated:** October 24, 2025  
**Status:** Complete and Production-Ready

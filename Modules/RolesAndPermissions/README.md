# Roles Module

**Version**: 1.0.0  
**Purpose**: Role management with tenant-specific and global role support

---

## 📋 Module Overview

The Roles module provides comprehensive role management for EkklesiaSoft, supporting both global system roles and tenant-specific custom roles. It enables fine-grained access control with a hierarchical role structure.

---

## 🎯 Features

### 1. **System Roles (Global)**
- ✅ Pre-defined system roles (SuperAdmin, EkklesiaAdmin, EkklesiaManager, EkklesiaUser)
- ✅ Protected from modification and deletion
- ✅ Available to all tenants
- ✅ Hierarchical level system

### 2. **Custom Roles (Tenant-Specific)**
- ✅ Tenants can create custom roles
- ✅ Isolated per tenant
- ✅ Full CRUD operations
- ✅ Custom permissions and descriptions

### 3. **Role Management**
- ✅ List roles with filtering
- ✅ Create custom roles
- ✅ Update custom roles
- ✅ Delete custom roles (with user check)
- ✅ Activate/deactivate roles
- ✅ Restore deleted roles

---

## 🏗️ Role Structure

### System Roles (Protected)

| Role | Level | Description | Tenant | Modifiable |
|------|-------|-------------|--------|------------|
| **SuperAdmin** | 1 | Full system access, manages all tenants | NULL | ❌ No |
| **EkklesiaAdmin** | 2 | Tenant administrator, full tenant access | NULL/Tenant | ❌ No |
| **EkklesiaManager** | 3 | Limited admin access within tenant | NULL/Tenant | ❌ No |
| **EkklesiaUser** | 4 | Standard user access | NULL/Tenant | ❌ No |

### Custom Roles

| Property | Description |
|----------|-------------|
| **Level** | 5-10 (custom roles) |
| **Tenant ID** | Required (tied to specific tenant) |
| **Is Custom** | TRUE |
| **Modifiable** | ✅ Yes (by tenant admins) |
| **Deletable** | ✅ Yes (if no users assigned) |

---

## 🚀 API Endpoints

Base URL: `/api/roles`  
**All endpoints require authentication (`auth:api` middleware)**

### List All Roles

```http
GET /api/roles

Query Parameters:
- active: Filter by active status (0 or 1)
- tenant_id: Filter by tenant (SuperAdmin only)
- is_custom: Filter by custom roles (0 or 1)
- search: Search by name or description
- per_page: Items per page (default: 15)

Authorization: Bearer {token}
Required Role: Any authenticated user (filtered by access)

Response (200):
{
  "data": [
    {
      "id": 1,
      "name": "SuperAdmin",
      "description": "Super Administrator...",
      "level": 1,
      "tenant_id": null,
      "is_custom": false,
      "active": 1,
      ...
    }
  ],
  "meta": { ... },
  "links": { ... }
}
```

### Get Role Details

```http
GET /api/roles/{id}

Authorization: Bearer {token}

Response (200):
{
  "role": {
    "id": 5,
    "name": "Youth Leader",
    "description": "Manages youth programs",
    "level": 5,
    "tenant_id": 1,
    "is_custom": true,
    ...
  },
  "stats": {
    "total_users": 5,
    "active_users": 4
  }
}
```

### Create Custom Role

```http
POST /api/roles

Body:
{
  "name": "Youth Leader",
  "description": "Manages youth programs and events",
  "level": 5,
  "tenant_id": 1  // Optional (auto-set for non-SuperAdmin)
}

Authorization: Bearer {token}
Required Role: SuperAdmin, EkklesiaAdmin, or EkklesiaManager

Response (201):
{
  "message": "Role created successfully",
  "role": { ... }
}
```

### Update Custom Role

```http
PUT /api/roles/{id}
PATCH /api/roles/{id}

Body:
{
  "name": "Updated Role Name",
  "description": "Updated description",
  "level": 6,
  "active": 1
}

Authorization: Bearer {token}
Required Role: SuperAdmin, EkklesiaAdmin, or EkklesiaManager

Response (200):
{
  "message": "Role updated successfully",
  "role": { ... }
}

Errors:
- 403: Cannot modify system roles
- 403: Can only modify roles for your tenant
```

### Delete Custom Role

```http
DELETE /api/roles/{id}

Authorization: Bearer {token}
Required Role: SuperAdmin, EkklesiaAdmin, or EkklesiaManager

Response (200):
{
  "message": "Role deleted successfully"
}

Errors:
- 403: Cannot delete system roles
- 422: Cannot delete role with assigned users
```

### Restore Role

```http
POST /api/roles/{id}/restore

Authorization: Bearer {token}
Required Role: SuperAdmin, EkklesiaAdmin, or EkklesiaManager

Response (200):
{
  "message": "Role restored successfully",
  "role": { ... }
}
```

### Activate Role

```http
POST /api/roles/{id}/activate

Authorization: Bearer {token}
Required Role: SuperAdmin, EkklesiaAdmin, or EkklesiaManager

Response (200):
{
  "message": "Role activated successfully",
  "role": { ... }
}
```

### Deactivate Role

```http
POST /api/roles/{id}/deactivate

Authorization: Bearer {token}
Required Role: SuperAdmin, EkklesiaAdmin, or EkklesiaManager

Response (200):
{
  "message": "Role deactivated successfully",
  "role": { ... }
}
```

---

## 💾 Database Schema

### Roles Table (Enhanced)

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT | Primary key |
| name | VARCHAR | Role name (unique) |
| description | TEXT | Role description |
| level | INT | Hierarchy level (1-10) |
| **tenant_id** | BIGINT | Tenant ID (NULL = global) |
| **is_custom** | BOOLEAN | 1=Custom, 0=System |
| active | INT | Status (1=Active, 0=Inactive) |
| deleted_at | TIMESTAMP | Soft delete timestamp |
| created_at | TIMESTAMP | Creation timestamp |
| updated_at | TIMESTAMP | Update timestamp |

**Indexes:**
- `name` (unique)
- `level`
- `tenant_id`
- `active, deleted_at` (composite)
- `tenant_id, active, deleted_at` (composite)

**Foreign Keys:**
- `tenant_id` → `tenants.id` (ON DELETE CASCADE)

---

## 🔧 Usage Examples

### Check Role Type

```php
$role = Role::find(5);

if ($role->isGlobal()) {
    // Global system role
}

if ($role->isCustom()) {
    // Tenant-specific custom role
}

if ($role->isSuperAdmin()) {
    // SuperAdmin role
}
```

### Query Roles

```php
// Get global (system) roles
$systemRoles = Role::global()->get();

// Get tenant-specific roles
$tenantRoles = Role::byTenant(1)->get();

// Get custom roles only
$customRoles = Role::custom()->get();

// Get active system roles
$activeSystemRoles = Role::system()->active()->get();
```

### Create Custom Role

```php
$role = Role::create([
    'name' => 'Volunteer Coordinator',
    'description' => 'Manages volunteers',
    'level' => 5,
    'tenant_id' => 1,
    'is_custom' => true,
    'active' => 1,
]);
```

### Assign Role to User

```php
$user = User::find(10);
$role = Role::where('name', 'Youth Leader')->first();

$user->role_id = $role->id;
$user->save();

// Or
$user->update(['role_id' => $role->id]);
```

---

## 🛡️ Authorization Rules

### Who Can Do What?

| Action | SuperAdmin | EkklesiaAdmin | EkklesiaManager | EkklesiaUser |
|--------|-----------|---------------|-----------------|--------------|
| View all roles | ✅ Yes | ✅ Global + Tenant | ✅ Global + Tenant | ✅ Global + Tenant |
| Create global role | ✅ Yes | ❌ No | ❌ No | ❌ No |
| Create tenant role | ✅ Yes | ✅ Yes | ✅ Yes | ❌ No |
| Update system role | ✅ Yes (limited) | ❌ No | ❌ No | ❌ No |
| Update custom role | ✅ Yes | ✅ Own tenant | ✅ Own tenant | ❌ No |
| Delete system role | ❌ No | ❌ No | ❌ No | ❌ No |
| Delete custom role | ✅ Yes | ✅ Own tenant | ✅ Own tenant | ❌ No |

### Role Visibility

- **SuperAdmin**: Sees all roles (global + all tenant roles)
- **Tenant Admins/Managers**: See global roles + their tenant's custom roles
- **Regular Users**: See global roles + their tenant's roles (read-only)

---

## 📊 Role Hierarchy

```
Level 1: SuperAdmin (highest privilege)
  └─ Full system access
  └─ Manages all tenants

Level 2: EkklesiaAdmin
  └─ Manages one or all tenants
  └─ Full tenant administration

Level 3: EkklesiaManager
  └─ Limited administrative access
  └─ Cannot manage users

Level 4: EkklesiaUser
  └─ Standard user access
  └─ Basic features only

Level 5-10: Custom Roles
  └─ Defined by tenants
  └─ Tenant-specific permissions
```

---

## 🎨 Best Practices

### Creating Custom Roles

1. **Use Descriptive Names**: "Youth Leader", "Finance Manager", etc.
2. **Set Appropriate Levels**: Levels 5-10 for custom roles
3. **Add Clear Descriptions**: Help users understand the role
4. **Consider User Limits**: Check tenant's max_users before assigning

### Managing Roles

1. **Don't Modify System Roles**: Create custom roles instead
2. **Check User Assignment**: Before deleting, reassign users
3. **Use Soft Deletes**: Don't force delete roles
4. **Maintain Hierarchy**: Higher levels = more privileges

---

## ⚠️ Important Notes

### System Role Protection

- System roles **cannot be deleted**
- System roles **cannot be modified** (except by SuperAdmin, limited)
- Attempting to modify system roles returns `403 Forbidden`

### Custom Role Limits

- Maximum **50 custom roles per tenant**
- Levels must be between **5 and 10**
- Names must be **unique** across all roles

### User Assignment

- **Cannot delete role** if users are assigned
- Must reassign users to different role first
- Check with: `$role->users()->count()`

---

## 🧪 Testing

```bash
# Create custom role
curl -X POST http://127.0.0.1:8000/api/roles \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"name":"Youth Leader","description":"Manages youth","level":5}'

# List roles
curl -X GET http://127.0.0.1:8000/api/roles \
  -H "Authorization: Bearer {token}"

# Update role
curl -X PUT http://127.0.0.1:8000/api/roles/5 \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"description":"Updated description"}'
```

---

## 📖 Related Modules

- **Authentication**: Core user authentication and role assignments
- **Tenants**: Tenant management and isolation

---

## 🚀 Future Enhancements

- [ ] Permission-based access control (beyond roles)
- [ ] Role templates for common use cases
- [ ] Role cloning
- [ ] Bulk user role assignment
- [ ] Role audit logs

---

**Built with ❤️ for flexible, scalable role management**


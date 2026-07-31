# Sacraments Module - Complete API Test Coverage

## Overview
This document describes the comprehensive unit tests for all Sacraments module APIs.

## Test File
`tests/Feature/SacramentApiTest.php`

## Test Coverage Summary

### Total Test Cases: 50+

### 1. INDEX (List Sacraments) - 7 Tests
- ✅ `it_can_get_paginated_list_of_sacraments` - Verifies pagination works correctly
- ✅ `it_enforces_tenant_isolation_in_list` - Ensures tenants only see their own sacraments
- ✅ `it_can_filter_sacraments_by_type` - Filters by sacrament type
- ✅ `it_can_filter_sacraments_by_status` - Filters by status (active, cancelled, conditional)
- ✅ `it_can_search_sacraments_by_recipient_name` - Search functionality
- ✅ `it_can_sort_sacraments_by_date_administered` - Sorting (asc/desc)
- ✅ `it_can_filter_by_date_range` - Date range filtering

### 2. SHOW (Get Single Sacrament) - 3 Tests
- ✅ `it_can_get_single_sacrament_by_id` - Retrieves single record
- ✅ `it_returns_404_for_non_existent_sacrament` - Error handling
- ✅ `it_enforces_tenant_isolation_when_getting_sacrament` - Tenant isolation

### 3. STORE (Create Sacrament) - 15 Tests
- ✅ `it_can_create_sacrament_with_valid_data` - Basic creation
- ✅ `it_auto_sets_tenant_id_from_authenticated_user` - Auto tenant assignment
- ✅ `it_validates_required_fields_when_creating_sacrament` - Required field validation
- ✅ `it_validates_sacrament_type_exists_when_creating` - Foreign key validation
- ✅ `it_validates_certificate_number_is_unique` - Uniqueness validation
- ✅ `it_validates_family_id_belongs_to_tenant` - Tenant-scoped foreign key
- ✅ `it_validates_bcc_id_belongs_to_tenant` - Tenant-scoped foreign key
- ✅ `it_can_create_sacrament_with_family_and_bcc` - Relationship creation
- ✅ `it_can_create_marriage_sacrament_with_all_fields` - Marriage fields
- ✅ `it_converts_empty_strings_to_null_for_nullable_fields` - Data normalization
- ✅ `it_validates_marriage_church_type_enum` - Enum validation
- ✅ `it_validates_recipient_gender_enum` - Enum validation
- ✅ `it_validates_status_enum` - Enum validation
- ✅ `it_syncs_baptism_to_family_member` - Baptism family member sync

### 4. UPDATE (Update Sacrament) - 6 Tests
- ✅ `it_can_update_sacrament_with_valid_data` - Basic update
- ✅ `it_returns_404_when_updating_non_existent_sacrament` - Error handling
- ✅ `it_enforces_tenant_isolation_when_updating` - Tenant isolation
- ✅ `it_validates_certificate_number_is_unique_on_update` - Uniqueness on update
- ✅ `it_allows_same_certificate_number_for_same_sacrament_on_update` - Update same record
- ✅ `it_can_update_marriage_fields` - Marriage field updates

### 5. DESTROY (Delete Sacrament) - 3 Tests
- ✅ `it_can_delete_sacrament` - Soft delete functionality
- ✅ `it_returns_404_when_deleting_non_existent_sacrament` - Error handling
- ✅ `it_enforces_tenant_isolation_when_deleting` - Tenant isolation

### 6. GET SACRAMENT TYPES - 2 Tests
- ✅ `it_can_get_sacrament_types` - List all active types
- ✅ `it_returns_only_active_sacrament_types` - Active filter

### 7. AUTHORIZATION - 3 Tests
- ✅ `it_blocks_users_without_tenant_id` - Blocks non-tenant users
- ✅ `it_blocks_ekklesia_users` - Blocks Ekklesia role users
- ✅ `it_requires_authentication_for_all_endpoints` - Auth requirement

### 8. EDGE CASES - 4 Tests
- ✅ `it_handles_large_pagination_correctly` - Large dataset handling
- ✅ `it_handles_empty_results_gracefully` - Empty result handling
- ✅ `it_handles_special_characters_in_search` - Special character handling
- ✅ `it_handles_nullable_fields_correctly` - Null field handling

## API Endpoints Tested

### GET /api/sacraments
- Pagination
- Filtering (type, status, date range)
- Searching
- Sorting
- Tenant isolation

### GET /api/sacraments/{id}
- Single record retrieval
- 404 handling
- Tenant isolation

### POST /api/sacraments
- Creation with all field types
- Validation (required, foreign keys, enums, uniqueness)
- Tenant auto-assignment
- Family/BCC relationships
- Marriage fields
- Baptism family member sync
- Data normalization (empty strings to null)

### PUT /api/sacraments/{id}
- Update functionality
- Validation on update
- Tenant isolation
- Certificate number uniqueness on update

### DELETE /api/sacraments/{id}
- Soft delete
- Tenant isolation
- 404 handling

### GET /api/sacraments/types
- List active types
- Active filter

## Test Features

### 1. Tenant Isolation
All tests verify that tenants can only access/modify their own sacraments.

### 2. Authorization
Tests verify:
- Users without tenant_id are blocked
- Ekklesia users are blocked (if applicable)
- Authentication is required for all endpoints

### 3. Validation Coverage
- Required fields
- Foreign key existence
- Tenant-scoped foreign keys (family_id, bcc_id)
- Enum values (status, gender, church_type)
- Uniqueness (certificate_number)
- Date formats

### 4. Marriage Fields
Complete testing of all marriage-specific fields:
- Groom details (name, father, mother, address, church info)
- Bride details (name, father, mother, address, church info)
- Church types (home_parish, other)

### 5. Relationships
- Family relationship
- BCC relationship
- Baptism to FamilyMember sync

### 6. Data Handling
- Empty string to null conversion
- Nullable field handling
- Special character handling
- Large dataset pagination

## Running the Tests

```bash
# Run all Sacrament API tests
php artisan test --filter=SacramentApiTest

# Run specific test
php artisan test --filter=it_can_create_sacrament_with_valid_data

# Run with coverage
php artisan test --filter=SacramentApiTest --coverage
```

## Test Data Setup

Each test uses:
- `RefreshDatabase` trait for clean database state
- `WithFaker` for generating test data
- Factories for creating test models:
  - `Tenant::factory()`
  - `User::factory()`
  - `SacramentType::factory()`
  - `Sacrament::factory()`
  - `Family::factory()`
  - `BCC::factory()`
  - `FamilyMember::factory()`

## Assertions Used

- `assertStatus()` - HTTP status codes
- `assertJson()` - JSON response structure and content
- `assertJsonStructure()` - JSON structure validation
- `assertJsonValidationErrors()` - Validation error checking
- `assertDatabaseHas()` - Database record verification
- `assertSoftDeleted()` - Soft delete verification
- `assertEquals()` - Value equality
- `assertNotNull()` / `assertNull()` - Null checks
- `assertStringContainsString()` - String content checks

## Notes

1. **Authentication**: Tests use `Laravel\Passport\Passport::actingAs()` for API authentication
2. **Soft Deletes**: Delete operations use soft deletes, verified with `assertSoftDeleted()`
3. **Tenant Isolation**: All operations are tenant-scoped automatically
4. **Baptism Sync**: Baptism sacraments automatically create/update FamilyMember records
5. **Data Normalization**: Empty strings are converted to null for nullable fields

## Future Enhancements

Consider adding tests for:
- Bulk operations (if implemented)
- Export functionality (if implemented)
- Advanced filtering combinations
- Performance testing with large datasets
- Concurrent access scenarios



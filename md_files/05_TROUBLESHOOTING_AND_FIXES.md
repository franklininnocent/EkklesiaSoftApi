# 🔧 Troubleshooting & Fixes

**EkklesiaSoft API** - Common Issues and Solutions

---

## 📚 Quick Fix Index

| Issue | Solution | File |
|-------|----------|------|
| CORS errors | Update cors.php config | [CORS](#1-cors-errors) |
| Passport errors | Reinstall keys | [Passport](#2-passport-errors) |
| Namespace errors | Fix controller namespaces | [Namespace](#3-namespace-errors) |
| Controller missing | Restore controllers | [Controller](#4-controller-errors) |

---

## 🔴 Common Issues

### 1. CORS Errors

**Symptoms:**
```
Access to XMLHttpRequest blocked by CORS policy
No 'Access-Control-Allow-Origin' header present
```

**Solution:**

```php
// config/cors.php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:4200',
        env('FRONTEND_URL', 'http://localhost:4200')
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Authorization'],
    'max_age' => 0,
    'supports_credentials' => true,
];
```

**Verify:**
```bash
# Test CORS
curl -H "Origin: http://localhost:4200" \
     -H "Access-Control-Request-Method: POST" \
     -H "Access-Control-Request-Headers: X-Requested-With" \
     -X OPTIONS http://127.0.0.1:8000/api/auth/login \
     -v
```

---

### 2. Passport Errors

**Issue A: "Private key does not exist"**

```bash
# Fix: Reinstall Passport keys
cd /var/www/html/EkklesiaSoft/EkklesiaSoftApi
php artisan passport:install --force

# Fix permissions
chmod 600 storage/oauth-private.key
chmod 600 storage/oauth-public.key

# Verify
ls -la storage/oauth-*.key
```

**Issue B: "Password grant client not found"**

```bash
# Create password grant client
php artisan passport:client --password

# If error, manually fix database:
# Update oauth_clients table:
# Set grant_types = '["password","refresh_token"]' (JSON array, not string)
```

**Issue C: "Call to undefined method Passport::useClientUuid()"**

```php
// Remove from app/Providers/AuthServiceProvider.php:
// Passport::useClientUuid(); // Delete this line
// Passport::ignoreRoutes(); // Delete this line
```

---

### 3. Namespace Errors

**Issue: "Class not found" or "Target class does not exist"**

**Solution:**

```php
// Modules/Authentication/routes/api.php
// ✅ Correct:
use Modules\Authentication\app\Http\Controllers\AuthenticationController;

Route::post('/login', [AuthenticationController::class, 'login']);

// ❌ Wrong:
use AuthenticationController; // Missing full namespace
```

**Verify namespaces:**
```bash
# Check controller namespace
head -5 Modules/Authentication/app/Http/Controllers/AuthenticationController.php

# Should show:
# namespace Modules\Authentication\app\Http\Controllers;
```

---

### 4. Controller Errors

**Issue: "Controller method not found"**

**Cause:** Controller file deleted or moved

**Solution:**

```bash
# Restore from backup or recreate
php artisan module:make-controller AuthenticationController Authentication

# Verify controller exists
ls -la Modules/Authentication/app/Http/Controllers/

# Check routes
php artisan route:list | grep auth
```

---

### 5. Database Errors

**Issue A: "SQLSTATE[23000]: Integrity constraint violation"**

```bash
# Fix: Clear and reseed database
php artisan migrate:fresh
php artisan db:seed
```

**Issue B: "Column not found"**

```bash
# Check migration status
php artisan migrate:status

# Run pending migrations
php artisan migrate

# Or refresh
php artisan migrate:refresh
```

---

### 6. Token Errors

**Issue: "Invalid or expired token"**

**Solutions:**

```bash
# 1. Clear all tokens
DELETE FROM oauth_access_tokens;
DELETE FROM oauth_refresh_tokens;

# 2. Login again to get fresh tokens

# 3. Check token expiry
SELECT expires_at FROM oauth_access_tokens WHERE user_id = 1;

# 4. Verify token lifetime in AuthServiceProvider
# Access: 6 hours, Refresh: 30 days
```

---

## ⚡ Quick Fixes

### Reset Everything

```bash
cd /var/www/html/EkklesiaSoft/EkklesiaSoftApi

# 1. Clear cache
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# 2. Reset database
php artisan migrate:fresh

# 3. Seed data
php artisan db:seed

# 4. Reinstall Passport
php artisan passport:install --force
chmod 600 storage/oauth-*.key

# 5. Create password client
php artisan passport:client --password

# 6. Test login
curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "franklininnocent.fs@gmail.com",
    "password": "Secrete*999"
  }'
```

---

## 🔍 Debugging Tools

### 1. Check Routes

```bash
# List all routes
php artisan route:list

# Filter by module
php artisan route:list | grep auth
php artisan route:list | grep tenant
```

### 2. Check Database

```bash
# Access MySQL
mysql -u root -p ekklesia_soft_db

# Check tables
SHOW TABLES;

# Check users
SELECT id, name, email, role_id FROM users;

# Check roles
SELECT * FROM roles;

# Check tenants
SELECT id, name, slug, active FROM tenants;
```

### 3. Check Logs

```bash
# View latest errors
tail -50 storage/logs/laravel.log

# Watch logs in real-time
tail -f storage/logs/laravel.log
```

### 4. Test API

```bash
# Test login
curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"franklininnocent.fs@gmail.com","password":"Secrete*999"}'

# Test protected route
curl -H "Authorization: Bearer {TOKEN}" \
  http://127.0.0.1:8000/api/auth/get-user
```

---

## 📊 Health Check Script

```bash
#!/bin/bash
# health-check.sh

echo "=== EkklesiaSoft API Health Check ==="

echo -e "\n1. Checking Laravel..."
php artisan --version

echo -e "\n2. Checking database connection..."
php artisan migrate:status | head -5

echo -e "\n3. Checking Passport keys..."
ls -la storage/oauth-*.key

echo -e "\n4. Checking routes..."
php artisan route:list | wc -l
echo "routes registered"

echo -e "\n5. Testing login endpoint..."
curl -s -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"franklininnocent.fs@gmail.com","password":"Secrete*999"}' \
  | head -3

echo -e "\n=== Health Check Complete ==="
```

---

## ✅ Verification Checklist

After fixing issues:

- [ ] `php artisan route:list` shows all routes
- [ ] Passport keys exist in `storage/`
- [ ] Database has all tables
- [ ] SuperAdmin user exists
- [ ] Login API returns access_token
- [ ] Protected routes require auth
- [ ] CORS headers present
- [ ] No errors in `storage/logs/laravel.log`

---

## 📝 Prevention Tips

### 1. Always Use Version Control

```bash
git status
git add .
git commit -m "Description of changes"
```

### 2. Backup Before Major Changes

```bash
# Backup database
mysqldump -u root -p ekklesia_soft_db > backup_$(date +%Y%m%d).sql

# Restore if needed
mysql -u root -p ekklesia_soft_db < backup_20251024.sql
```

### 3. Test After Changes

```bash
# Run tests
php artisan test

# Test specific feature
php artisan test --filter=AuthenticationTest
```

### 4. Keep Dependencies Updated

```bash
composer update
php artisan migrate
```

---

## 🆘 Still Having Issues?

### Check These Files

1. `.env` - Environment configuration
2. `config/database.php` - Database config
3. `config/cors.php` - CORS settings
4. `app/Providers/AuthServiceProvider.php` - Passport config
5. `Modules/*/routes/api.php` - Route definitions

### Common `.env` Issues

```bash
# Verify database connection
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ekklesia_soft_db
DB_USERNAME=root
DB_PASSWORD=your_password

# Verify app URL
APP_URL=http://127.0.0.1:8000

# Verify frontend URL
FRONTEND_URL=http://localhost:4200
```

---

**Last Updated:** October 24, 2025  
**This document consolidates all fixes applied to the EkklesiaSoft API**

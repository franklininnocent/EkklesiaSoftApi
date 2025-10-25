# 🔐 Authentication System - Complete Documentation

**EkklesiaSoft API** - Laravel Passport OAuth2 Authentication with Refresh Tokens

---

## 📚 Table of Contents

1. [Overview](#overview)
2. [Quick Start](#quick-start)
3. [API Endpoints](#api-endpoints)
4. [Token Management](#token-management)
5. [Refresh Token Flow](#refresh-token-flow)
6. [API Response Format](#api-response-format)
7. [Frontend Integration](#frontend-integration)
8. [Security Best Practices](#security-best-practices)
9. [Troubleshooting](#troubleshooting)

---

## 🎯 Overview

### Authentication Features

✅ **Laravel Passport OAuth2** - Industry-standard authentication  
✅ **JWT Access Tokens** - 6-hour validity  
✅ **Refresh Tokens** - 30-day validity  
✅ **Token Rotation** - New tokens on refresh  
✅ **Automatic Revocation** - On logout  
✅ **Multi-tenant Support** - User-tenant relationships  
✅ **Role-based Access** - User roles and permissions  

### Token Lifetimes

| Token Type | Lifetime | Purpose |
|------------|----------|---------|
| **Access Token** | 6 hours | API access (short-lived for security) |
| **Refresh Token** | 30 days | Obtain new access tokens |
| **Personal Access Token** | 6 months | Long-lived tokens (admin use) |

### Architecture

```
┌─────────────────┐
│   Frontend      │
│  (Angular 20)   │
└────────┬────────┘
         │ 1. Login (email/password)
         ↓
┌─────────────────┐
│   Laravel API   │
│  (Passport)     │
└────────┬────────┘
         │ 2. Generate tokens
         ↓
┌─────────────────┐
│  Token Service  │
│  (TokenService) │
└────────┬────────┘
         │ 3. Return: access_token, refresh_token, expiry_time
         ↓
┌─────────────────┐
│  localStorage   │
│  (Frontend)     │
└─────────────────┘
```

---

## 🚀 Quick Start

### 1. Install Passport

```bash
cd /var/www/html/EkklesiaSoft/EkklesiaSoftApi

# Install Passport (already done)
composer require laravel/passport

# Run migrations
php artisan migrate

# Install Passport keys
php artisan passport:install --force

# Create password grant client
php artisan passport:client --password

# Fix permissions
chmod 600 storage/oauth-private.key
chmod 600 storage/oauth-public.key
```

### 2. Test Authentication

```bash
# Login
curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "franklininnocent.fs@gmail.com",
    "password": "Secrete*999"
  }'

# Response:
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
  "refresh_token": "def50200a1b2c3d4...",
  "expiry_time": "2025-10-24 15:18:47",
  "user_id": 1,
  "role_id": 1,
  "token_type": "Bearer",
  "message": "Login successful"
}
```

---

## 📡 API Endpoints

### Authentication Routes

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/auth/register` | Public | Register new user |
| POST | `/api/auth/login` | Public | Login with credentials |
| POST | `/api/auth/refresh` | Public | Refresh access token |
| POST | `/api/auth/logout` | Protected | Logout (revoke tokens) |
| GET | `/api/auth/get-user` | Protected | Get logged-in user details |

### 1. Register

**Endpoint:** `POST /api/auth/register`

**Request:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "SecurePass123!",
  "password_confirmation": "SecurePass123!"
}
```

**Response:** (Same as Login)

### 2. Login

**Endpoint:** `POST /api/auth/login`

**Request:**
```json
{
  "email": "john@example.com",
  "password": "SecurePass123!"
}
```

**Success Response:** `200 OK`
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiI1Mjg1Y2M2Ny0wOTQ0LTQ0YzMtOWFjYi1hZjFhMzBkZWY3MzQiLCJqdGkiOiI0NmE5NmU2Yi0wZWY0LTRiMWUtOTY2MS0xMDk5MTViYzQ4ODUiLCJpYXQiOjE3NjEyOTc1MjcsIm5iZiI6MTc2MTI5NzUyNywiZXhwIjoxNzYxMzE5MTI3LCJzdWIiOiI0Iiwic2NvcGVzIjpbXX0...",
  "refresh_token": "def50200a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6...",
  "expiry_time": "2025-10-24 21:18:47",
  "user_id": 4,
  "role_id": 1,
  "token_type": "Bearer",
  "message": "Login successful"
}
```

**Error Response:** `401 Unauthorized`
```json
{
  "message": "Invalid credentials",
  "errors": {
    "email": ["The provided credentials are incorrect."]
  }
}
```

### 3. Refresh Token

**Endpoint:** `POST /api/auth/refresh`

**Request:**
```json
{
  "refresh_token": "def50200a1b2c3d4e5f6..."
}
```

**Success Response:** `200 OK`
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
  "refresh_token": "def50200b2c3d4e5f6g7...",
  "expiry_time": "2025-10-24 22:30:00",
  "user_id": 4,
  "role_id": 1,
  "token_type": "Bearer",
  "message": "Token refreshed successfully"
}
```

**Error Response:** `401 Unauthorized`
```json
{
  "message": "Invalid or expired refresh token"
}
```

### 4. Logout

**Endpoint:** `POST /api/auth/logout`

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response:** `200 OK`
```json
{
  "message": "Successfully logged out"
}
```

### 5. Get User

**Endpoint:** `GET /api/auth/get-user`

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response:** `200 OK`
```json
{
  "success": true,
  "message": "User details retrieved successfully",
  "data": {
    "id": 4,
    "name": "John Doe",
    "email": "john@example.com",
    "role_id": 1,
    "tenant_id": null
  }
}
```

**Notes:**
- `created_at` and `updated_at` are excluded from response
- Returns only essential user information

---

## 🔄 Token Management

### TokenService Class

**Location:** `app/Services/TokenService.php`

**Methods:**

| Method | Description |
|--------|-------------|
| `createTokens($user)` | Generate access + refresh tokens |
| `refreshTokens($refreshToken)` | Get new tokens using refresh token |
| `revokeAllUserTokens($userId)` | Revoke all tokens (logout) |
| `getPasswordClient()` | Get OAuth password client |

**Usage Example:**

```php
use App\Services\TokenService;

// In Controller
public function login(Request $request) {
    $user = User::where('email', $request->email)->first();
    
    if (!$user || !Hash::check($request->password, $user->password)) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }
    
    $tokenService = new TokenService();
    $tokenData = $tokenService->createTokens($user);
    
    return response()->json([
        'access_token' => $tokenData['access_token'],
        'refresh_token' => $tokenData['refresh_token'],
        'expiry_time' => $tokenData['expiry_time'],
        'user_id' => $user->id,
        'role_id' => $user->role_id,
        'token_type' => 'Bearer',
        'message' => 'Login successful'
    ]);
}
```

---

## 🔄 Refresh Token Flow

### How Refresh Tokens Work

```
┌─────────────────────────────────────────────────────────────┐
│                   Refresh Token Lifecycle                   │
└─────────────────────────────────────────────────────────────┘

1. User logs in
   ↓
2. Receive: access_token (6h) + refresh_token (30d)
   ↓
3. Frontend stores both in localStorage
   ↓
4. Use access_token for API calls
   ↓
5. After 6 hours, access_token expires
   ↓
6. API returns 401 Unauthorized
   ↓
7. Frontend automatically calls /auth/refresh
   ↓
8. Backend validates refresh_token
   ↓
9. Backend issues NEW access_token + NEW refresh_token
   ↓
10. OLD tokens are revoked
    ↓
11. Frontend updates localStorage
    ↓
12. Retry failed API call with new token
```

### Implementation Details

**Backend (TokenService):**
```php
public function refreshTokens($refreshToken)
{
    DB::beginTransaction();
    try {
        // Find refresh token
        $oldRefreshToken = DB::table('oauth_refresh_tokens')
            ->where('id', $refreshToken)
            ->where('revoked', false)
            ->first();
        
        if (!$oldRefreshToken) {
            throw new \Exception('Invalid refresh token');
        }
        
        // Get user from access token
        $accessToken = DB::table('oauth_access_tokens')
            ->where('id', $oldRefreshToken->access_token_id)
            ->first();
        
        $user = User::find($accessToken->user_id);
        
        // Revoke old tokens
        $this->revokeAccessToken($oldRefreshToken->access_token_id);
        $this->revokeRefreshToken($refreshToken);
        
        // Create new tokens
        $tokenData = $this->createTokens($user);
        
        DB::commit();
        return $tokenData;
    } catch (\Exception $e) {
        DB::rollback();
        throw $e;
    }
}
```

**Frontend (Angular):**
```typescript
// HTTP Interceptor
intercept(req: HttpRequest<any>, next: HttpHandler): Observable<HttpEvent<any>> {
  return next.handle(req).pipe(
    catchError((error: HttpErrorResponse) => {
      if (error.status === 401 && !req.url.includes('/auth/refresh')) {
        // Token expired, try to refresh
        return this.authService.refreshTokens().pipe(
          switchMap(() => {
            // Retry original request with new token
            const clonedReq = this.addToken(req);
            return next.handle(clonedReq);
          }),
          catchError(err => {
            // Refresh failed, redirect to login
            this.authService.logout();
            return throwError(err);
          })
        );
      }
      return throwError(error);
    })
  );
}
```

---

## 📋 API Response Format

### Standard Success Response

```json
{
  "success": true,
  "message": "Operation successful",
  "data": {
    // Resource data
  }
}
```

### Standard Error Response

```json
{
  "success": false,
  "message": "Error description",
  "errors": {
    "field_name": ["Validation error message"]
  }
}
```

### Authentication Response Format

```json
{
  "access_token": "JWT token string",
  "refresh_token": "Refresh token string",
  "expiry_time": "2025-10-24 21:18:47",
  "user_id": 4,
  "role_id": 1,
  "token_type": "Bearer",
  "message": "Login successful"
}
```

---

## 🖥️ Frontend Integration

### Angular Setup

**Environment Configuration:**

```typescript
// src/environments/environment.ts
export const environment = {
  apiUrl: 'http://127.0.0.1:8000/api',
  tokenKey: 'ekklesia_token',
  refreshTokenKey: 'ekklesia_refresh_token',
  expiryTimeKey: 'ekklesia_expiry_time',
  userIdKey: 'ekklesia_user_id',
  roleIdKey: 'ekklesia_role_id'
};
```

**AuthService:**

```typescript
import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { tap } from 'rxjs/operators';

@Injectable({ providedIn: 'root' })
export class AuthService {
  constructor(private http: HttpClient) {}
  
  login(email: string, password: string) {
    return this.http.post(`${environment.apiUrl}/auth/login`, { email, password })
      .pipe(
        tap((response: any) => {
          localStorage.setItem(environment.tokenKey, response.access_token);
          localStorage.setItem(environment.refreshTokenKey, response.refresh_token);
          localStorage.setItem(environment.expiryTimeKey, response.expiry_time);
          localStorage.setItem(environment.userIdKey, response.user_id);
          localStorage.setItem(environment.roleIdKey, response.role_id);
        })
      );
  }
  
  refreshTokens() {
    const refreshToken = localStorage.getItem(environment.refreshTokenKey);
    return this.http.post(`${environment.apiUrl}/auth/refresh`, { refresh_token: refreshToken })
      .pipe(
        tap((response: any) => {
          localStorage.setItem(environment.tokenKey, response.access_token);
          localStorage.setItem(environment.refreshTokenKey, response.refresh_token);
          localStorage.setItem(environment.expiryTimeKey, response.expiry_time);
        })
      );
  }
  
  logout() {
    const token = localStorage.getItem(environment.tokenKey);
    return this.http.post(`${environment.apiUrl}/auth/logout`, {}, {
      headers: { Authorization: `Bearer ${token}` }
    }).pipe(
      tap(() => {
        localStorage.clear();
      })
    );
  }
}
```

**HTTP Interceptor:**

```typescript
import { HttpInterceptorFn } from '@angular/common/http';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const token = localStorage.getItem(environment.tokenKey);
  
  if (token && !req.url.includes('/auth/login') && !req.url.includes('/auth/register')) {
    req = req.clone({
      setHeaders: { Authorization: `Bearer ${token}` }
    });
  }
  
  return next(req);
};
```

---

## 🔒 Security Best Practices

### 1. Token Storage

**✅ Do:**
- Store tokens in `localStorage` or `sessionStorage`
- Clear tokens on logout
- Validate token expiry before API calls

**❌ Don't:**
- Store tokens in cookies (vulnerable to CSRF)
- Store tokens in global variables
- Expose tokens in URLs

### 2. HTTPS Only

```bash
# Production: Always use HTTPS
https://api.ekklesiasoft.com/api/auth/login
```

### 3. Token Rotation

- ✅ Generate NEW refresh token on each refresh
- ✅ Revoke OLD refresh token immediately
- ✅ Prevents token reuse attacks

### 4. Short Access Token Lifetime

- ✅ 6 hours is a good balance
- ✅ Limits damage if token is compromised
- ✅ Refresh tokens handle longer sessions

### 5. Input Validation

```php
// StoreTenantRequest.php
public function rules() {
    return [
        'email' => 'required|email|unique:users',
        'password' => 'required|min:8|regex:/[a-z]/|regex:/[A-Z]/|regex:/[0-9]/',
    ];
}
```

### 6. Rate Limiting

```php
// routes/api.php
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/auth/login', [AuthenticationController::class, 'login']);
});
```

---

## 🔧 Troubleshooting

### Issue 1: "Unauthenticated" Error

**Symptoms:**
```json
{
  "message": "Unauthenticated"
}
```

**Solutions:**
1. Check if token is being sent in header
2. Verify token hasn't expired
3. Ensure Passport keys exist:
   ```bash
   ls -la storage/oauth-*.key
   chmod 600 storage/oauth-*.key
   ```

### Issue 2: "Invalid Refresh Token"

**Causes:**
- Refresh token already used (revoked)
- Refresh token expired (30 days)
- Token doesn't exist in database

**Solution:**
```bash
# Check refresh tokens in database
SELECT * FROM oauth_refresh_tokens WHERE revoked = 0;

# Force user to re-login
```

### Issue 3: CORS Errors

**Solution:**
```php
// config/cors.php
'allowed_origins' => ['http://localhost:4200'],
'allowed_methods' => ['*'],
'allowed_headers' => ['*'],
'exposed_headers' => ['Authorization'],
```

### Issue 4: Passport Keys Missing

**Symptoms:**
```
Error: Private key does not exist
```

**Solution:**
```bash
php artisan passport:install --force
chmod 600 storage/oauth-private.key
chmod 600 storage/oauth-public.key
```

---

## 📊 Database Schema

### oauth_access_tokens

| Column | Type | Description |
|--------|------|-------------|
| id | VARCHAR(100) | Unique token ID |
| user_id | BIGINT | User who owns token |
| client_id | CHAR(36) | OAuth client ID |
| name | VARCHAR | Token name |
| scopes | TEXT | Token scopes |
| revoked | BOOLEAN | Is revoked? |
| created_at | TIMESTAMP | Creation time |
| updated_at | TIMESTAMP | Last update |
| expires_at | DATETIME | Expiration time |

### oauth_refresh_tokens

| Column | Type | Description |
|--------|------|-------------|
| id | VARCHAR(100) | Unique token ID |
| access_token_id | VARCHAR(100) | Related access token |
| revoked | BOOLEAN | Is revoked? |
| expires_at | DATETIME | Expiration time |

---

## ✅ Testing Checklist

- [ ] User can register
- [ ] User can login
- [ ] Access token works for protected routes
- [ ] Access token expires after 6 hours
- [ ] Refresh token works to get new access token
- [ ] Old tokens are revoked after refresh
- [ ] Logout revokes all tokens
- [ ] Invalid credentials return 401
- [ ] Expired tokens return 401
- [ ] CORS headers are correct

---

## 📝 Next Steps

1. ✅ **Authentication Complete** - All endpoints working
2. 📋 **Add 2FA** - Two-factor authentication (future)
3. 📋 **Email Verification** - Verify user emails (future)
4. 📋 **Password Reset** - Forgot password flow (future)
5. 📋 **Social Login** - Google/Facebook OAuth (future)

---

**Last Updated:** October 24, 2025  
**Status:** Complete and Production-Ready

**See also:**
- `01_SETUP_AND_ARCHITECTURE.md` - Setup Guide
- `03_ROLES_AND_PERMISSIONS_COMPLETE.md` - RBAC System
- `04_TENANTS_API_COMPLETE.md` - Tenants Management
- `05_TROUBLESHOOTING_AND_FIXES.md` - Common Issues


#!/bin/bash

echo "=========================================="
echo "Testing SuperAdmin Authentication"
echo "=========================================="
echo ""

# Login with superadmin
echo "1. Testing Login with SuperAdmin..."
LOGIN_RESPONSE=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "email": "franklininnocent.fs@gmail.com",
    "password": "Secrete*999"
  }')

echo "$LOGIN_RESPONSE" | jq '.'
echo ""

# Extract token
TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.access_token')

if [ "$TOKEN" = "null" ] || [ -z "$TOKEN" ]; then
    echo "❌ Login failed - no token received"
    exit 1
fi

echo "✅ Token received: ${TOKEN:0:50}..."
echo ""

# Test authenticated endpoint
echo "2. Testing /api/auth/user..."
USER_RESPONSE=$(curl -s -X GET http://localhost:8000/api/auth/user \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN")

echo "$USER_RESPONSE" | jq '.'
echo ""

# Test debug token
echo "3. Testing /api/debug-token..."
DEBUG_RESPONSE=$(curl -s -X GET http://localhost:8000/api/debug-token \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $TOKEN")

echo "$DEBUG_RESPONSE" | jq '.'
echo ""

if echo "$USER_RESPONSE" | jq -e '.email' > /dev/null 2>&1; then
    echo "✅ Authentication working!"
else
    echo "❌ Authentication failed!"
fi

echo ""
echo "=========================================="

#!/bin/bash

# Test Bank Verification on Heroku
# Replace YOUR_HEROKU_APP_URL with your actual Heroku app URL

HEROKU_URL="https://your-heroku-app-name.herokuapp.com"

echo "=== Testing Bank Verification on Heroku ==="
echo "Heroku URL: $HEROKU_URL"
echo ""

# Test OPay account (your account)
echo "1. Testing OPay Account: 9036444724"
echo "Bank Code: 999992 (OPay Digital Services Limited)"
echo ""

curl -X POST "$HEROKU_URL/api/public/test-bank-verification" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "account_number": "9036444724",
    "bank_code": "999992"
  }' | jq '.'

echo ""
echo "----------------------------------------"
echo ""

# Test MoniePoint account (sample)
echo "2. Testing MoniePoint Account: 1234567890"
echo "Bank Code: 50515 (Moniepoint MFB)"
echo ""

curl -X POST "$HEROKU_URL/api/public/test-bank-verification" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "account_number": "1234567890",
    "bank_code": "50515"
  }' | jq '.'

echo ""
echo "----------------------------------------"
echo ""

# Test PalmPay account (sample)
echo "3. Testing PalmPay Account: 1234567890"
echo "Bank Code: 999991 (PalmPay)"
echo ""

curl -X POST "$HEROKU_URL/api/public/test-bank-verification" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "account_number": "1234567890",
    "bank_code": "999991"
  }' | jq '.'

echo ""
echo "----------------------------------------"
echo ""

# Test different OPay account number formats
echo "4. Testing OPay with different account number formats"
echo ""

# Format 1: 9036444724
echo "Format 1: 9036444724"
curl -X POST "$HEROKU_URL/api/public/test-bank-verification" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "account_number": "9036444724",
    "bank_code": "999992"
  }' | jq '.data.verification_result'

echo ""
echo ""

# Format 2: 09036444724
echo "Format 2: 09036444724"
curl -X POST "$HEROKU_URL/api/public/test-bank-verification" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "account_number": "09036444724",
    "bank_code": "999992"
  }' | jq '.data.verification_result'

echo ""
echo ""

# Format 3: +2349036444724
echo "Format 3: +2349036444724"
curl -X POST "$HEROKU_URL/api/public/test-bank-verification" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "account_number": "+2349036444724",
    "bank_code": "999992"
  }' | jq '.data.verification_result'

echo ""
echo "----------------------------------------"
echo ""

# Test available banks endpoint
echo "5. Testing Available Banks List"
echo ""

curl -X GET "$HEROKU_URL/api/banks" \
  -H "Accept: application/json" | jq '.data[] | select(.name | test("(?i)(opay|moniepoint|palm)")) | {name: .name, code: .code}'

echo ""
echo "=== Test Complete ==="
echo ""
echo "Instructions:"
echo "1. Replace 'your-heroku-app-name' in HEROKU_URL with your actual Heroku app name"
echo "2. Make sure your Heroku app has the correct PAYSTACK_SECRET_KEY and PAYSTACK_PUBLIC_KEY configured"
echo "3. Run this script: ./test_heroku_bank_verification.sh"
echo ""
echo "Expected Results:"
echo "- If keys are configured correctly: You should see verification results"
echo "- If keys are missing: You'll see 'Invalid key' errors"
echo "- If account exists: You'll see account holder name"
echo "- If account doesn't exist: You'll see appropriate error message"

# Bank Verification Testing Guide

## Overview

This guide helps you test bank account verification for fintech companies like OPay, MoniePoint, and PalmPay using the Paystack API.

## Correct Bank Codes for Fintech Companies

Based on our testing, here are the correct bank codes:

| Fintech Company | Bank Code | Bank Name                            |
| --------------- | --------- | ------------------------------------ |
| OPay            | 999992    | OPay Digital Services Limited (OPay) |
| MoniePoint      | 50515     | Moniepoint MFB                       |
| PalmPay         | 999991    | PalmPay                              |

## Testing Your OPay Account

### Your Account Details

-   **Account Number**: 9036444724
-   **Bank Code**: 999992 (OPay)

## API Endpoints for Testing

### 1. Public Test Endpoint (No Authentication Required)

```
POST /api/public/test-bank-verification
```

### 2. Business Test Endpoint (Requires Authentication)

```
POST /api/business/test-bank-verification
```

### 3. Direct Bank Verification

```
POST /api/banks/verify-account
```

### 4. List All Banks

```
GET /api/banks/
```

### 5. Check Paystack Configuration

```
GET /api/banks/test-config
```

## Test Commands

### Using cURL

#### Test OPay Account

```bash
curl -X POST https://your-heroku-app.herokuapp.com/api/public/test-bank-verification \
  -H "Content-Type: application/json" \
  -d '{
    "account_number": "9036444724",
    "bank_code": "999992"
  }'
```

#### Test MoniePoint Account

```bash
curl -X POST https://your-heroku-app.herokuapp.com/api/public/test-bank-verification \
  -H "Content-Type: application/json" \
  -d '{
    "account_number": "1234567890",
    "bank_code": "50515"
  }'
```

#### Test PalmPay Account

```bash
curl -X POST https://your-heroku-app.herokuapp.com/api/public/test-bank-verification \
  -H "Content-Type: application/json" \
  -d '{
    "account_number": "1234567890",
    "bank_code": "999991"
  }'
```

#### List All Banks

```bash
curl -X GET https://your-heroku-app.herokuapp.com/api/banks/
```

#### Check Configuration

```bash
curl -X GET https://your-heroku-app.herokuapp.com/api/banks/test-config
```

### Using JavaScript/Fetch

```javascript
// Test OPay account
const testOPay = async () => {
    try {
        const response = await fetch(
            "https://your-heroku-app.herokuapp.com/api/public/test-bank-verification",
            {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                },
                body: JSON.stringify({
                    account_number: "9036444724",
                    bank_code: "999992",
                }),
            }
        );

        const data = await response.json();
        console.log("OPay Test Result:", data);
    } catch (error) {
        console.error("Error testing OPay:", error);
    }
};

// Test MoniePoint account
const testMoniePoint = async () => {
    try {
        const response = await fetch(
            "https://your-heroku-app.herokuapp.com/api/public/test-bank-verification",
            {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                },
                body: JSON.stringify({
                    account_number: "1234567890",
                    bank_code: "50515",
                }),
            }
        );

        const data = await response.json();
        console.log("MoniePoint Test Result:", data);
    } catch (error) {
        console.error("Error testing MoniePoint:", error);
    }
};

// Test PalmPay account
const testPalmPay = async () => {
    try {
        const response = await fetch(
            "https://your-heroku-app.herokuapp.com/api/public/test-bank-verification",
            {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                },
                body: JSON.stringify({
                    account_number: "1234567890",
                    bank_code: "999991",
                }),
            }
        );

        const data = await response.json();
        console.log("PalmPay Test Result:", data);
    } catch (error) {
        console.error("Error testing PalmPay:", error);
    }
};
```

## Expected Responses

### Successful Verification

```json
{
    "success": true,
    "message": "Bank verification test completed",
    "data": {
        "verification_result": {
            "success": true,
            "data": {
                "account_number": "9036444724",
                "account_name": "SEGUN EMMANUEL",
                "bank_id": 999992
            },
            "message": "Account verification successful"
        },
        "test_details": {
            "account_number": "9036444724",
            "bank_code": "999992",
            "tested_at": "2024-01-15 15:30:00"
        },
        "available_fintech_banks": [
            {
                "name": "OPay Digital Services Limited (OPay)",
                "code": "999992"
            },
            {
                "name": "Moniepoint MFB",
                "code": "50515"
            },
            {
                "name": "PalmPay",
                "code": "999991"
            }
        ],
        "known_fintech_codes": {
            "OPay": "999992",
            "MoniePoint": "50515",
            "PalmPay": "999991"
        }
    }
}
```

### Failed Verification (Invalid Account)

```json
{
    "success": true,
    "message": "Bank verification test completed",
    "data": {
        "verification_result": {
            "success": false,
            "message": "Invalid account number"
        },
        "test_details": {
            "account_number": "1234567890",
            "bank_code": "999992",
            "tested_at": "2024-01-15 15:30:00"
        },
        "available_fintech_banks": [...],
        "known_fintech_codes": {...}
    }
}
```

### Configuration Error

```json
{
    "success": true,
    "message": "Bank verification test completed",
    "data": {
        "verification_result": {
            "success": false,
            "message": "Invalid key"
        },
        "test_details": {...},
        "available_fintech_banks": [],
        "known_fintech_codes": {...}
    }
}
```

## Troubleshooting

### 1. "Invalid key" Error

This means the Paystack API keys are not configured properly.

**Solution:**

-   Set the environment variables in your Heroku app:

```bash
heroku config:set PAYSTACK_SECRET_KEY=sk_test_your_secret_key_here
heroku config:set PAYSTACK_PUBLIC_KEY=pk_test_your_public_key_here
```

### 2. "Invalid account number" Error

This means the account number doesn't exist or is incorrect.

**Solutions:**

-   Verify the account number is correct
-   Try different account number formats (with/without country code)
-   Ensure the bank code is correct

### 3. "Bank not found" Error

This means the bank code is incorrect.

**Solution:**

-   Use the correct bank codes listed above
-   Check the `/api/banks/` endpoint for the latest bank codes

## Testing Different Account Number Formats

For OPay accounts, try these formats:

-   `9036444724` (10 digits)
-   `09036444724` (11 digits with leading zero)
-   `+2349036444724` (with country code)

## Environment Setup for Heroku

1. **Set Paystack Keys:**

```bash
heroku config:set PAYSTACK_SECRET_KEY=sk_test_your_secret_key_here
heroku config:set PAYSTACK_PUBLIC_KEY=pk_test_your_public_key_here
```

2. **Deploy the Application:**

```bash
git add .
git commit -m "Add bank verification testing endpoints"
git push heroku main
```

3. **Test the Endpoints:**

```bash
# Test health endpoint
curl https://your-heroku-app.herokuapp.com/api/public/health

# Test OPay account
curl -X POST https://your-heroku-app.herokuapp.com/api/public/test-bank-verification \
  -H "Content-Type: application/json" \
  -d '{"account_number": "9036444724", "bank_code": "999992"}'
```

## Notes

-   The test endpoints are designed for development and testing purposes
-   For production, use the authenticated business endpoints
-   Always validate account numbers before processing payments
-   Keep your Paystack API keys secure and never expose them in client-side code

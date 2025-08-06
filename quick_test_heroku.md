# Quick Test Commands for Heroku Bank Verification

## Replace `YOUR_HEROKU_APP` with your actual Heroku app name

### 1. Test OPay Account (Your Account: 9036444724)

```bash
curl -X POST "https://YOUR_HEROKU_APP.herokuapp.com/api/public/test-bank-verification" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "account_number": "9036444724",
    "bank_code": "999992"
  }'
```

### 2. Test MoniePoint Account

```bash
curl -X POST "https://YOUR_HEROKU_APP.herokuapp.com/api/public/test-bank-verification" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "account_number": "1234567890",
    "bank_code": "50515"
  }'
```

### 3. Test PalmPay Account

```bash
curl -X POST "https://YOUR_HEROKU_APP.herokuapp.com/api/public/test-bank-verification" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "account_number": "1234567890",
    "bank_code": "999991"
  }'
```

### 4. Get Available Banks List

```bash
curl -X GET "https://YOUR_HEROKU_APP.herokuapp.com/api/banks" \
  -H "Accept: application/json"
```

### 5. Test Direct Bank Verification Endpoint

```bash
curl -X POST "https://YOUR_HEROKU_APP.herokuapp.com/api/banks/verify-account" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "account_number": "9036444724",
    "bank_code": "999992"
  }'
```

## Expected Results

### If Paystack Keys are NOT configured:

```json
{
    "success": false,
    "message": "Invalid key"
}
```

### If Paystack Keys are configured correctly:

```json
{
    "success": true,
    "data": {
        "account_number": "9036444724",
        "account_name": "SEGUN EMMANUEL",
        "bank_id": 999992
    }
}
```

### If account doesn't exist:

```json
{
    "success": false,
    "message": "Account number does not exist"
}
```

## Correct Bank Codes for Fintech Companies

-   **OPay**: `999992`
-   **MoniePoint**: `50515`
-   **PalmPay**: `999991`

## Important Notes

1. **Account Number Format**: OPay accounts can be 10-11 digits
2. **Bank Code Length**: Fintech bank codes are 5-6 digits, not 3
3. **Validation Fixed**: Updated validation to allow longer bank codes
4. **Test Your Account**: Use your OPay account `9036444724` with bank code `999992`

## Troubleshooting

If you get "Invalid key" errors:

1. Check that `PAYSTACK_SECRET_KEY` is set in Heroku config vars
2. Make sure the key starts with `sk_test_` or `sk_live_`
3. Verify the key is valid in your Paystack dashboard

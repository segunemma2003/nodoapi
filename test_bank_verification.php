<?php

require_once 'vendor/autoload.php';

use App\Services\PaystackService;

// Load Laravel environment
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Bank Verification Test Script ===\n\n";

$paystackService = new PaystackService();

// Test OPay account with correct bank code
echo "Testing OPay Account: 9036444724\n";
echo "Bank Code: 999992 (OPay Digital Services Limited)\n";
$opayResult = $paystackService->verifyAccountNumber('9036444724', '999992');
echo "Result: " . json_encode($opayResult, JSON_PRETTY_PRINT) . "\n\n";

// Test MoniePoint account with correct bank code
echo "Testing MoniePoint Account: 1234567890\n";
echo "Bank Code: 50515 (Moniepoint MFB)\n";
$moniepointResult = $paystackService->verifyAccountNumber('1234567890', '50515');
echo "Result: " . json_encode($moniepointResult, JSON_PRETTY_PRINT) . "\n\n";

// Test PalmPay account with correct bank code
echo "Testing PalmPay Account: 1234567890\n";
echo "Bank Code: 999991 (PalmPay)\n";
$palmPayResult = $paystackService->verifyAccountNumber('1234567890', '999991');
echo "Result: " . json_encode($palmPayResult, JSON_PRETTY_PRINT) . "\n\n";

// Test with different variations of OPay account number
echo "Testing OPay with different account number formats:\n";
$opayAccountNumbers = ['9036444724', '09036444724', '+2349036444724'];
foreach ($opayAccountNumbers as $accountNumber) {
    echo "Account Number: $accountNumber\n";
    $result = $paystackService->verifyAccountNumber($accountNumber, '999992');
    echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
}

// List all banks to confirm the codes
echo "=== Confirming Bank Codes ===\n";
$banksResult = $paystackService->listBanks();
if ($banksResult['success']) {
    $banks = $banksResult['data'];
    $fintechBanks = array_filter($banks, function($bank) {
        $name = strtolower($bank['name']);
        return strpos($name, 'opay') !== false ||
               strpos($name, 'moniepoint') !== false ||
               strpos($name, 'palm') !== false ||
               strpos($name, 'fintech') !== false;
    });

    echo "Fintech Banks Available:\n";
    foreach ($fintechBanks as $bank) {
        echo "Name: {$bank['name']}, Code: {$bank['code']}\n";
    }
} else {
    echo "Failed to list banks: " . $banksResult['message'] . "\n";
}

echo "\n=== Test Complete ===\n";
echo "\nNote: If you see 'Invalid key' errors, the Paystack API keys need to be configured in .env file\n";
echo "Required environment variables:\n";
echo "- PAYSTACK_SECRET_KEY=sk_test_... or sk_live_...\n";
echo "- PAYSTACK_PUBLIC_KEY=pk_test_... or pk_live_...\n";

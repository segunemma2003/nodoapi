<?php

// Test account number validation
echo "=== Account Number Validation Test ===\n\n";

$testAccountNumbers = [
    '9036444724',    // 10 digits - should pass
    '09036444724',   // 11 digits - should pass
    '903644472',     // 9 digits - should fail
    '90364447245',   // 11 digits - should pass
    '903644472456',  // 12 digits - should fail
    '903644472a',    // contains letter - should fail
    '9036444724 ',   // contains space - should fail
    ' 9036444724',   // leading space - should fail
];

foreach ($testAccountNumbers as $accountNumber) {
    $isValid = preg_match('/^\d{10,11}$/', $accountNumber);
    $length = strlen($accountNumber);
    echo "Account: '$accountNumber' (length: $length) - " . ($isValid ? "✅ VALID" : "❌ INVALID") . "\n";
}

echo "\n=== Bank Code Validation Test ===\n\n";

$testBankCodes = [
    '999992',    // 6 digits - should pass
    '50515',     // 5 digits - should pass
    '044',       // 3 digits - should pass
    '999991',    // 6 digits - should pass
    '99',        // 2 digits - should fail
    '9999999999', // 10 digits - should pass
    '99999999999', // 11 digits - should fail
    '99999a',    // contains letter - should fail
];

foreach ($testBankCodes as $bankCode) {
    $isValid = preg_match('/^\d{3,10}$/', $bankCode);
    $length = strlen($bankCode);
    echo "Bank Code: '$bankCode' (length: $length) - " . ($isValid ? "✅ VALID" : "❌ INVALID") . "\n";
}

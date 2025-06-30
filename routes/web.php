<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/test-resend', function () {
    try {
        \Illuminate\Support\Facades\Mail::raw('Test email from Laravel using Resend!', function ($message) {
            $message->to('segunemma2003@gmail.com') // Replace with your email
                    ->subject('Test Email from Laravel + Resend'.config('app.frontend_url') . '/login');
        });

        return response()->json([
            'success' => true,
            'message' => 'Test email sent successfully via Resend!'
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to send email: ' . $e->getMessage()
        ], 500);
    }
});


Route::get('/test-s3-debug', function() {
    $debugInfo = [];

    try {
        // Step 1: Check configuration
        $debugInfo['config'] = [
            'aws_key_set' => !empty(env('AWS_ACCESS_KEY_ID')),
            'aws_secret_set' => !empty(env('AWS_SECRET_ACCESS_KEY')),
            'aws_key_length' => strlen(env('AWS_ACCESS_KEY_ID', '')),
            'aws_secret_length' => strlen(env('AWS_SECRET_ACCESS_KEY', '')),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'laravel_env' => env('APP_ENV'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version()
        ];

        // Step 2: Test basic S3 connection
        $s3Client = Storage::disk('s3');
        $debugInfo['s3_disk_created'] = true;

        // Step 3: Try to list bucket (this will fail if credentials are wrong)
        try {
            $files = $s3Client->files('');
            $debugInfo['bucket_accessible'] = true;
            $debugInfo['existing_files_count'] = count($files);
            $debugInfo['sample_files'] = array_slice($files, 0, 5);
        } catch (\Exception $e) {
            $debugInfo['bucket_accessible'] = false;
            $debugInfo['bucket_error'] = $e->getMessage();
        }

        // Step 4: Try to create a test file
        $testFileName = 'heroku-debug-' . time() . '.txt';
        $testContent = 'Heroku S3 Debug Test - ' . now() . ' - Random: ' . rand(1000, 9999);

        Log::info('Attempting S3 upload', [
            'filename' => $testFileName,
            'content_length' => strlen($testContent)
        ]);

        $uploadResult = $s3Client->put($testFileName, $testContent);
        $debugInfo['upload_result'] = $uploadResult;
        $debugInfo['upload_success'] = $uploadResult !== false;

        // Step 5: Check if file exists immediately after upload
        $exists = $s3Client->exists($testFileName);
        $debugInfo['file_exists_after_upload'] = $exists;

        // Step 6: Try to get the file URL
        if ($exists) {
            $url = $s3Client->url($testFileName);
            $debugInfo['file_url'] = $url;

            // Step 7: Try to read the file back
            try {
                $retrievedContent = $s3Client->get($testFileName);
                $debugInfo['file_readable'] = true;
                $debugInfo['content_matches'] = ($retrievedContent === $testContent);
                $debugInfo['retrieved_content_length'] = strlen($retrievedContent);
            } catch (\Exception $e) {
                $debugInfo['file_readable'] = false;
                $debugInfo['read_error'] = $e->getMessage();
            }

            // Step 8: Clean up test file
            try {
                $s3Client->delete($testFileName);
                $debugInfo['cleanup_success'] = true;
            } catch (\Exception $e) {
                $debugInfo['cleanup_success'] = false;
                $debugInfo['cleanup_error'] = $e->getMessage();
            }
        } else {
            $debugInfo['file_url'] = null;
            $debugInfo['file_readable'] = false;
            $debugInfo['content_matches'] = false;
        }

        // Step 9: Check AWS SDK version and capabilities
        $debugInfo['aws_sdk_info'] = [
            'loaded_extensions' => get_loaded_extensions(),
            'curl_available' => function_exists('curl_init'),
            'openssl_available' => extension_loaded('openssl'),
        ];

        Log::info('S3 Debug completed', $debugInfo);

        return response()->json([
            'success' => true,
            'message' => 'S3 Debug completed',
            'debug_info' => $debugInfo
        ], 200);

    } catch (\Exception $e) {
        $debugInfo['fatal_error'] = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_slice($e->getTrace(), 0, 5) // Limit trace for readability
        ];

        Log::error('S3 Debug failed', $debugInfo);

        return response()->json([
            'success' => false,
            'message' => 'S3 Debug failed',
            'error' => $e->getMessage(),
            'debug_info' => $debugInfo
        ], 500);
    }
});

// Also add a route to check your actual S3 bucket contents
Route::get('/list-s3-files', function() {
    try {
        $s3Client = Storage::disk('s3');

        // List all files in the bucket
        $allFiles = $s3Client->allFiles();

        // List files in receipts directory specifically
        $receiptFiles = $s3Client->allFiles('receipts');

        return response()->json([
            'success' => true,
            'bucket_name' => env('AWS_BUCKET'),
            'total_files' => count($allFiles),
            'all_files' => array_slice($allFiles, 0, 20), // Show first 20 files
            'receipt_files' => $receiptFiles,
            'receipts_count' => count($receiptFiles)
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'bucket_name' => env('AWS_BUCKET')
        ]);
    }
});

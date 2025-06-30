<?php

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


Route::get('/test-s3', function() {
    try {
        Storage::disk('s3')->put('test.txt', 'Hello World');
        $url = Storage::disk('s3')->url('test.txt');
        Storage::disk('s3')->delete('test.txt');
        return "S3 working! Test URL: " . $url;
    } catch (\Exception $e) {
        return "S3 Error: " . $e->getMessage();
    }
});

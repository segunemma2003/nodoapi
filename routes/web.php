<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/test-resend', function () {
    try {
        \Illuminate\Support\Facades\Mail::raw('Test email from Laravel using Resend!', function ($message) {
            $message->to('segunemma2003@gmail.com') // Replace with your email
                    ->subject('Test Email from Laravel + Resend');
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

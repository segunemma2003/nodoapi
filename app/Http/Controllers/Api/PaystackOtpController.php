<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\PaystackService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\PurchaseOrder;

class PaystackOtpController extends Controller
{
    protected $paystackService;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystackService = $paystackService;
    }

    /**
     * Finalize a Paystack transfer with OTP
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function finalizeTransfer(Request $request)
    {
        $request->validate([
            'transfer_code' => 'required|string',
            'otp' => 'required|string',
        ]);

        $transferCode = $request->input('transfer_code');
        $otp = $request->input('otp');

        $result = $this->paystackService->finalizeTransferWithOtp($transferCode, $otp);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $result['data'] ?? null
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message'],
            'error' => $result['error'] ?? null
        ], 400);
    }
}

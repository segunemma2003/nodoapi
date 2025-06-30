<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Payment;

class UploadReceiptToS3 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $localPath;
    protected $filename;
    protected $paymentId;

    public function __construct($localPath, $filename, $paymentId)
    {
        $this->localPath = $localPath;
        $this->filename = $filename;
        $this->paymentId = $paymentId;
    }

    public function handle()
    {
        try {
            $fileStream = Storage::get($this->localPath);

            $s3Path = 'receipts/' . $this->filename;
            Storage::disk('s3')->put($s3Path, $fileStream);
            $receiptUrl = Storage::disk('s3')->url($s3Path);

            $payment = Payment::find($this->paymentId);
            if ($payment) {
                $payment->update(['receipt_path' => $receiptUrl]);
            }

            // Clean up local file
            Storage::delete($this->localPath);

            Log::info("Receipt uploaded for payment {$this->paymentId}");
        } catch (\Exception $e) {
            Log::error("Receipt upload failed: " . $e->getMessage());
        }
    }
}

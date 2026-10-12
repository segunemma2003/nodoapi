<?php

namespace App\Console\Commands;

use App\Mail\CreditPaymentDueDigest;
use App\Models\PurchaseOrder;
use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCreditPaymentDueReminders extends Command
{
    protected $signature = 'payments:send-due-reminders';

    protected $description = 'Email the accounts team a digest of credit purchase orders that are overdue or due within 3 days';

    public function handle(): int
    {
        $unpaidCreditPos = PurchaseOrder::creditPlan()
            ->with(['business', 'vendor'])
            ->whereNotNull('due_date')
            ->get();

        $overdue = $unpaidCreditPos->filter(fn (PurchaseOrder $po) => $po->isOverdue())->values();

        $dueSoon = $unpaidCreditPos
            ->reject(fn (PurchaseOrder $po) => $po->isOverdue())
            ->filter(fn (PurchaseOrder $po) => now()->diffInDays($po->due_date, false) <= 3)
            ->values();

        if ($overdue->isEmpty() && $dueSoon->isEmpty()) {
            $this->info('No credit purchase orders overdue or due soon. No digest sent.');
            return self::SUCCESS;
        }

        $recipients = $this->getRecipients();

        Mail::queue($recipients)->send(new CreditPaymentDueDigest($overdue, $dueSoon));

        Log::info('Sent credit payment due digest', [
            'overdue_count' => $overdue->count(),
            'due_soon_count' => $dueSoon->count(),
            'recipient_count' => count($recipients),
        ]);

        $this->info("Sent digest to {$this->recipientCountLabel($recipients)}: {$overdue->count()} overdue, {$dueSoon->count()} due soon.");

        return self::SUCCESS;
    }

    /**
     * Recipients come from the admin_notification_email system setting plus an
     * optional comma-separated PAYMENT_DUE_REMINDER_RECIPIENTS env override,
     * so the team list isn't hardcoded into the codebase here.
     */
    private function getRecipients(): array
    {
        $adminEmail = SystemSetting::getValue('admin_notification_email', 'support@foodstuff.store');

        $extra = array_filter(array_map('trim', explode(',', (string) env('PAYMENT_DUE_REMINDER_RECIPIENTS', ''))));

        return array_values(array_unique(array_merge([$adminEmail], $extra)));
    }

    private function recipientCountLabel(array $recipients): string
    {
        $count = count($recipients);
        return $count === 1 ? '1 recipient' : "{$count} recipients";
    }
}

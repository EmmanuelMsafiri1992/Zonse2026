<?php

namespace App\Console\Commands;

use App\Models\SmsMessage;
use App\Models\Workspace;
use App\Sms\SmsService;
use App\Tenancy\WorkspaceContext;
use Illuminate\Console\Command;
use Modules\Appointments\Models\Appointment;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Sms\InvoiceTexts;
use Throwable;

class SendSmsReminders extends Command
{
    /** Most overdue reminders one invoice receives. */
    public const MAX_OVERDUE_REMINDERS = 3;

    /** Days between overdue reminders for the same invoice. */
    public const OVERDUE_INTERVAL_DAYS = 7;

    protected $signature = 'zonseo:send-sms-reminders';

    protected $description = 'Text overdue-invoice and next-day appointment reminders for workspaces that switched them on';

    public function handle(SmsService $sms, WorkspaceContext $context, InvoiceTexts $texts): int
    {
        $total = 0;
        $failures = 0;

        Workspace::query()->whereNotNull('settings->sms->provider')->with('modules')->chunkById(100, function ($workspaces) use ($sms, $context, $texts, &$total, &$failures) {
            foreach ($workspaces as $workspace) {
                try {
                    $sent = $context->run($workspace, function (Workspace $workspace) use ($sms, $texts) {
                        $count = 0;
                        if ($workspace->hasModule('invoicing') && $sms->wants($workspace, 'overdue')) {
                            $count += $this->overdueInvoices($texts);
                        }
                        if ($workspace->hasModule('appointments') && $sms->wants($workspace, 'appointments')) {
                            $count += $this->appointments($workspace, $sms);
                        }

                        return $count;
                    });
                    if ($sent) {
                        $this->line($workspace->name.': '.$sent.' queued');
                    }
                    $total += $sent;
                } catch (Throwable $e) {
                    $failures++;
                    report($e);
                    $this->error($workspace->name.': '.$e->getMessage());
                }
            }
        });

        $this->info($total.' reminders queued'.($failures ? ', '.$failures.' workspaces failed' : '').'.');

        return $failures ? self::FAILURE : self::SUCCESS;
    }

    /** Weekly reminders for unpaid overdue invoices, at most three per invoice. */
    protected function overdueInvoices(InvoiceTexts $texts): int
    {
        $count = 0;
        Invoice::query()->overdue()->where('balance', '>', 0)->whereNotNull('contact_id')->with('workspace')->each(function (Invoice $invoice) use ($texts, &$count) {
            $previous = SmsMessage::query()->where('purpose', 'overdue')->whereMorphedTo('subject', $invoice)->where('status', '!=', 'failed');
            if ((clone $previous)->count() >= self::MAX_OVERDUE_REMINDERS
                || (clone $previous)->where('created_at', '>', now()->subDays(self::OVERDUE_INTERVAL_DAYS))->exists()) {
                return;
            }
            if ($texts->sendFor($invoice, 'overdue', $texts->overdue($invoice))) {
                $count++;
            }
        });

        return $count;
    }

    /** One reminder for each appointment booked for tomorrow (in the workspace's time zone). */
    protected function appointments(Workspace $workspace, SmsService $sms): int
    {
        $tomorrow = now($workspace->timezone ?: config('app.timezone'))->addDay();
        $count = 0;

        Appointment::query()->whereIn('status', Appointment::ACTIVE_STATUSES)->whereNotNull('contact_id')
            ->where('starts_at', '>=', $tomorrow->copy()->startOfDay()->format('Y-m-d H:i:s'))
            ->where('starts_at', '<=', $tomorrow->copy()->endOfDay()->format('Y-m-d H:i:s'))
            ->with(['contact', 'service'])
            ->each(function (Appointment $appointment) use ($workspace, $sms, &$count) {
                $alreadySent = SmsMessage::query()->where('purpose', 'appointment')->whereMorphedTo('subject', $appointment)->where('status', '!=', 'failed')->exists();
                if ($alreadySent || ! $appointment->contact) {
                    return;
                }
                $body = SmsService::prefix($workspace).'Reminder: '.$appointment->displayTitle().' tomorrow, '.$appointment->starts_at->format('D j M \a\t H:i').'.'
                    .($workspace->phone ? ' To change it call '.$workspace->phone.'.' : '');
                if ($sms->sendToContact($appointment->contact, $body, ['purpose' => 'appointment', 'subject' => $appointment, 'sent_by' => null])) {
                    $count++;
                }
            });

        return $count;
    }
}

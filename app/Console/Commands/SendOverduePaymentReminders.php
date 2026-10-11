<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Rental;
use App\Models\RentalReminder;
use App\Models\User;
use App\Support\FonnteMediaSender;
use App\Support\OverdueDuration;
use App\Support\RentalFinancialSummary;
use App\Support\StoreCache;
use App\Support\WhatsappLinkSettings;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class SendOverduePaymentReminders extends Command
{
    protected $signature = 'rentals:send-overdue-payment-reminders
        {--dry-run : Do not send; only show what would be sent}
        {--force : Ignore interval checks}
        {--now= : Override today date (YYYY-MM-DD) for testing}
        {--limit=200 : Max rentals to process}';

    protected $description = 'Send WhatsApp overdue payment reminders for rentals with outstanding balance.';

    public function handle()
    {
        $taxPercent = max(0, (float) StoreCache::get('pricing_tax_percent', 0));
        $intervalHours = max(1, (int) env('OVERDUE_PAYMENT_REMINDER_INTERVAL_HOURS', 24));
        $limit = max(1, min(2000, (int) $this->option('limit')));
        $now = $this->resolveNow();

        $actor = User::query()
            ->get()
            ->first(fn (User $u) => $u->mayOperateRentTransactions());

        if (! $actor) {
            $this->error('No admin/super_admin user found to generate invoices.');
            return 1;
        }

        $query = Rental::query()
            ->where('status', 'active')
            ->whereNotNull('phone')
            ->whereDate('end_date', '<', $now->toDateString())
            ->latest()
            ->limit($limit);

        $rentals = $query->get();
        if ($rentals->isEmpty()) {
            $this->info('No overdue rentals with outstanding balance found.');
            return 0;
        }

        $baseUrl = WhatsappLinkSettings::publicBaseUrl();
        $baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : rtrim((string) config('app.url'), '/');
        if ($baseUrl === '') {
            $this->error('Public base URL is empty. Configure WHATSAPP_MEDIA_BASE_URL or StoreCache whatsapp_public_base_url.');
            return 1;
        }

        $request = Request::create($baseUrl);
        $sent = 0;
        $skipped = 0;

        foreach ($rentals as $rental) {
            $target = FonnteMediaSender::normalizeTargetPhone((string) ($rental->phone ?? ''));
            if ($target === '') {
                $skipped++;
                continue;
            }

            $reminder = RentalReminder::query()
                ->where('rental_id', $rental->id)
                ->where('kind', 'overdue_payment')
                ->latest()
                ->first();

            if (! $this->option('force') && $reminder && $reminder->sent_at) {
                $diff = $reminder->sent_at->diffInHours($now);
                if ($diff < $intervalHours) {
                    $skipped++;
                    continue;
                }
            }

            $rental->loadMissing('outfits');
            $fin = RentalFinancialSummary::summarize($rental, $taxPercent);
            $totalDue = (float) ($fin['net_incl_tax'] ?? 0);
            $amountPaid = (float) ($rental->amount_paid ?? 0);
            $balance = max(0, $totalDue - $amountPaid);
            if ($balance <= 0.004) {
                $skipped++;
                continue;
            }

            $end = Carbon::parse($rental->end_date, (string) config('app.timezone'));
            $overdueSeconds = OverdueDuration::overdueSecondsForEndDate($end, $now);
            $overdueHuman = OverdueDuration::formatSeconds($overdueSeconds);
            if (trim($overdueHuman) === '') {
                $daysLate = max(1, $end->copy()->startOfDay()->diffInDays($now->copy()->startOfDay()));
                $overdueHuman = $daysLate.' day'.($daysLate === 1 ? '' : 's').' overdue';
            }
            $paidLabel = $rental->payment_kind === 'down_payment' ? 'Down payment' : 'Amount paid';
            $message = $this->buildMessage($rental->customer_name, $overdueHuman, $balance, $totalDue, $amountPaid, $paidLabel);

            $invoice = Invoice::where('rental_id', $rental->id)->latest()->first();
            if (! $invoice) {
                $invoice = $this->generateInvoiceAs($rental, $request, $actor);
            }

            if (! $invoice || ! $invoice->png_path || ! Storage::disk('local')->exists($invoice->png_path)) {
                $this->warn("Rental {$rental->id}: invoice image missing, skipping.");
                $skipped++;
                continue;
            }

            $file = basename((string) $invoice->png_path);
            $signedImage = $this->externalSignedDownloadUrl($rental->id, $file, (string) now()->timestamp, $baseUrl);
            $uploadBinary = Storage::disk('local')->get($invoice->png_path);

            if ($this->option('dry-run')) {
                $this->line("DRY RUN → {$target} (rental {$rental->id}) Rp ".number_format($balance, 0, ',', '.'));
                $sent++;
                continue;
            }

            $result = FonnteMediaSender::sendMedia(
                $request,
                $target,
                $message,
                $signedImage,
                "invoice-{$rental->id}.jpg",
                $uploadBinary,
            );

            $row = RentalReminder::create([
                'rental_id' => $rental->id,
                'kind' => 'overdue_payment',
                'target' => $target,
                'sent_at' => $result['success'] ? now() : null,
                'status' => $result['success'] ? 'sent' : 'failed',
                'meta' => [
                    'overdue_seconds' => $overdueSeconds,
                    'overdue_human' => $overdueHuman,
                    'balance_due' => $balance,
                    'total_due' => $totalDue,
                    'amount_paid' => $amountPaid,
                    'media_mode' => $result['media_mode'] ?? null,
                    'error' => $result['error'] ?? null,
                    'details' => $result['details'] ?? null,
                    'provider_response' => $result['provider_response'] ?? null,
                ],
            ]);

            if (! $row) {
                $this->warn("Rental {$rental->id}: reminder log not saved.");
            }

            if (! $result['success']) {
                $this->warn("Rental {$rental->id}: failed to send (".$result['error'].")");
                continue;
            }

            $sent++;
        }

        $this->info("Done. Sent: {$sent}, skipped: {$skipped}");
        return 0;
    }

    private function resolveNow(): Carbon
    {
        $raw = trim((string) $this->option('now'));
        $tz = (string) config('app.timezone');
        if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return Carbon::createFromFormat('Y-m-d', $raw, $tz)->startOfDay();
        }

        return Carbon::now($tz)->startOfDay();
    }

    private function buildMessage(string $name, string $overdueHuman, float $balance, float $totalDue, float $amountPaid, string $paidLabel): string
    {
        $nm = trim($name) !== '' ? trim($name) : 'Customer';
        $bal = number_format($balance, 0, ',', '.');
        $tot = number_format($totalDue, 0, ',', '.');
        $paid = number_format($amountPaid, 0, ',', '.');
        $overdueHuman = trim($overdueHuman) !== '' ? trim($overdueHuman) : 'Overdue';
        $paidLabel = trim($paidLabel) !== '' ? trim($paidLabel) : 'Amount paid';

        return "Overdue payment reminder\n"
            ."Name: {$nm}\n"
            ."Overdue: {$overdueHuman}\n"
            ."Total due: Rp {$tot}\n"
            ."{$paidLabel}: Rp {$paid}\n"
            ."Balance: Rp {$bal}\n";
    }

    private function externalSignedDownloadUrl(int $rentalId, string $file, string $version, string $publicBaseUrl): string
    {
        $signed = URL::signedRoute('invoices.download', [
            'rental' => $rentalId,
            'file' => $file,
            'v' => $version,
        ], absolute: false);

        $parts = parse_url($signed);
        if (! is_array($parts) || empty($parts['path'])) {
            return $signed;
        }

        $pathWithQuery = $parts['path'].(isset($parts['query']) ? ('?'.$parts['query']) : '');

        return Str::finish($publicBaseUrl, '/').ltrim($pathWithQuery, '/');
    }

    private function generateInvoiceAs(Rental $rental, Request $request, User $actor): ?Invoice
    {
        $controller = app(\App\Http\Controllers\InvoiceController::class);
        $request->setUserResolver(fn () => $actor);
        $controller->generate($rental, $request);

        return Invoice::where('rental_id', $rental->id)->latest()->first();
    }
}

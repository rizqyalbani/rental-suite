<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\StoreSetting;
use App\Support\FonnteMediaSender;
use App\Support\InvoiceImageRaster;
use App\Support\RentalFinancialSummary;
use App\Support\StoreCache;
use App\Support\WhatsappLinkSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Dompdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class InvoiceController extends Controller
{
    private function invoiceJpegLooksBroken(string $binary): bool
    {
        $len = strlen($binary);
        if ($len < 10_000) {
            return true;
        }
        if (function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($binary);
            if (is_array($info) && isset($info[0], $info[1])) {
                $w = (int) $info[0];
                $h = (int) $info[1];
                if ($w < 360 || $h < 720) {
                    return true;
                }
            }
        }

        return false;
    }
    /**
     * @return array{
     *     tax_percent: float,
     *     subtotal: float,
     *     gross: float,
     *     pre_tax_net: float,
     *     rental_discount: float,
     *     rental_discount_type: string,
     *     rental_discount_amount: float,
     *     overdue_fee_amount: float,
     *     overdue_amount_paid: float,
     *     overdue_balance_due: float,
     *     tax_amount: float,
     *     grand_total: float,
     *     amount_paid: float,
     *     rent_amount_paid: float,
     *     balance_due: float,
     *     overdue_payment_method: string|null,
     *     items: array<int, array<string, float|string|int|null>>
     * }
     */
    private function pricingSummary(Rental $rental): array
    {
        $taxPercent = max(0, (float) StoreCache::get('pricing_tax_percent', 0));
        $rental->loadMissing('outfits');
        $fin = RentalFinancialSummary::summarize($rental, $taxPercent);

        $items = collect($fin['lines'] ?? [])
            ->map(function (array $L) use ($rental) {
                $rawCode = (string) ($L['code_label'] ?? $L['code'] ?? '');
                $primaryCode = trim(explode(' / ', $rawCode, 2)[0] ?? '');
                $outfit = $rental->outfits->firstWhere('code', $primaryCode);

                return [
                    'id' => $outfit ? (int) $outfit->id : null,
                    'code' => $rawCode,
                    'unit_price_per_day' => (float) ($L['price'] ?? 0),
                    'days' => (int) ($L['days'] ?? 1),
                    'line_gross' => (float) ($L['line_gross'] ?? 0),
                    'item_discount' => (float) ($L['discount'] ?? 0),
                    'item_discount_type' => (string) ($L['discount_type'] ?? 'fixed'),
                    'line_net' => (float) ($L['line_net'] ?? 0),
                ];
            })
            ->values()
            ->all();

        $overdueFee = (float) ($fin['overdue_fee_amount'] ?? 0);
        $grandTotal = (float) $fin['net_incl_tax'];
        $rentPaid = (float) ($rental->amount_paid ?? 0);
        $overduePaid = (float) ($rental->overdue_amount_paid ?? 0);
        $overdueChange = (float) ($rental->overdue_change_amount ?? 0);
        $amountPaid = $rentPaid + $overduePaid;

        // Same change-vs-refund split the edit modal and activity log already
        // show (computed once at item-removal approval time) — without this,
        // the receipt lumps a removal's refund into one undifferentiated
        // "Change" line, indistinguishable from ordinary checkout change.
        $removalLogChanges = RentalActivityLog::where('rental_id', $rental->id)
            ->where('action', 'item_removal_approved')
            ->orderByDesc('id')
            ->first(['changes'])?->changes ?? [];
        $itemRemovalChangeAmount = $removalLogChanges['item_removal_change_amount'][1] ?? null;
        $itemRemovalRefundAmount = $removalLogChanges['item_removal_refund_amount'][1] ?? null;

        return [
            'tax_percent' => (float) $fin['tax_rate'],
            'subtotal' => (float) $fin['sum_after_item_discounts'],
            'gross' => (float) $fin['gross'],
            'pre_tax_net' => (float) $fin['pre_tax_net'],
            'rental_discount' => (float) ($rental->discount ?? 0),
            'rental_discount_type' => (string) ($rental->discount_type ?? 'fixed'),
            'rental_discount_amount' => (float) $fin['order_discount_amount'],
            'overdue_fee_rate' => (float) ($rental->overdue_fee_rate ?? 0),
            'overdue_fee_days' => (int) ($rental->overdue_fee_days ?? 0),
            'overdue_fee_amount' => $overdueFee,
            'overdue_amount_paid' => $overduePaid,
            'overdue_change_amount' => $overdueChange,
            'overdue_balance_due' => max(0, $overdueFee - $overduePaid),
            'tax_amount' => (float) $fin['tax_amount'],
            'grand_total' => $grandTotal,
            'amount_paid' => $amountPaid,
            'rent_amount_paid' => $rentPaid,
            'balance_due' => max(0, $grandTotal - $amountPaid),
            'overdue_payment_method' => $rental->overdue_payment_method,
            'rental_days' => (int) ($fin['rental_days'] ?? 1),
            'extra_days' => (int) ($fin['extra_days'] ?? 0),
            'extra_charge' => (float) ($fin['extra_charge'] ?? 0),
            'items' => $items,
            'item_removal_change_amount' => $itemRemovalChangeAmount !== null ? (float) $itemRemovalChangeAmount : null,
            'item_removal_refund_amount' => $itemRemovalRefundAmount !== null ? (float) $itemRemovalRefundAmount : null,
        ];
    }

    public function generate(Rental $rental, Request $request)
    {
        $actor = $request->user();
        if (! $actor || ! $actor->mayGenerateInvoices()) {
            return response()->json(['error' => 'Your account cannot generate invoices.'], 403);
        }

        $maxSeconds = max(30, min(600, (int) env('INVOICE_GENERATE_MAX_SECONDS', 240)));
        @set_time_limit($maxSeconds);

        $payload = $this->doGenerate($rental, $request->boolean('force'));
        if (! ($payload['success'] ?? false)) {
            $status = (int) ($payload['status'] ?? 500);
            $err = (string) ($payload['error'] ?? 'Failed to generate invoice.');

            return response()->json(['error' => $err], $status);
        }

        return response()->json($payload);
    }

    public function regenerate(Rental $rental, Request $request)
    {
        if (! $request->hasValidSignature(false)) {
            abort(403);
        }

        $maxSeconds = max(30, min(600, (int) env('INVOICE_GENERATE_MAX_SECONDS', 240)));
        @set_time_limit($maxSeconds);

        $payload = $this->doGenerate($rental, true);
        if (! ($payload['success'] ?? false)) {
            abort((int) ($payload['status'] ?? 500), (string) ($payload['error'] ?? 'Failed to generate invoice.'));
        }

        return redirect()->to((string) ($payload['signed_view_url'] ?? $this->buildExternalSignedInvoiceViewUrl($rental)));
    }

    private function doGenerate(Rental $rental, bool $force): array
    {
        $pricingEnabled = (bool) StoreCache::get('pricing_enabled', true);
        if ($pricingEnabled) {
            $rental->loadMissing('outfits');
            $taxPercent = max(0, (float) StoreCache::get('pricing_tax_percent', 0));
            $fin = RentalFinancialSummary::summarize($rental, $taxPercent);
            $totalDue = (float) ($fin['net_incl_tax'] ?? 0);
            $amountPaid = (float) ($rental->amount_paid ?? 0);
            $balance = max(0, $totalDue - $amountPaid);
            $nowDate = Carbon::now((string) config('app.timezone'))->startOfDay();
            $endDate = $rental->end_date ? Carbon::parse($rental->end_date, (string) config('app.timezone'))->startOfDay() : null;
            $needsAutoCharge = $rental->status === 'active'
                && $endDate !== null
                && $endDate->lt($nowDate)
                && $balance <= 0.004
                && ((float) ($rental->overdue_fee_amount ?? 0)) <= 0.004
                && $rental->overdue_fee_applied_at === null;
            if ($needsAutoCharge) {
                $rental->forceFill([
                    'overdue_base_end_date' => $rental->overdue_base_end_date ?? $rental->end_date,
                    'overdue_merged_at' => $rental->overdue_merged_at ?? now(),
                    'overdue_fee_rate' => 100000,
                    'overdue_fee_days' => 1,
                    'overdue_fee_amount' => 100000,
                    'overdue_fee_applied_at' => now(),
                ])->save();
                $rental->refresh();
            }
        }

        $existingInvoice = Invoice::where('rental_id', $rental->id)->latest('id')->first();
        if ($existingInvoice && ! $force) {
            $pdfAvailable = $existingInvoice->pdf_path && Storage::disk('local')->exists($existingInvoice->pdf_path);
            $imageAvailable = $existingInvoice->png_path && Storage::disk('local')->exists($existingInvoice->png_path);
            $generatedAt = $existingInvoice->generated_at ? Carbon::parse($existingInvoice->generated_at) : null;
            $latestRentalUpdate = $rental->updated_at ? Carbon::parse($rental->updated_at) : null;
            $latestTemplateUpdate = $this->invoiceTemplateLastModifiedAt();
            $latestSettingUpdate = null;
            if ($rental->store_id !== null) {
                $keys = [
                    'store_logo_path',
                    'store_primary_color',
                    'store_admin_phone_1',
                    'store_admin_phone_2',
                    'store_admin_phone',
                    'store_contact_phone',
                    'store_contact_address',
                    'pricing_tax_percent',
                ];
                $rawMax = StoreSetting::query()
                    ->where('store_id', $rental->store_id)
                    ->whereIn('key', $keys)
                    ->max('updated_at');
                if ($rawMax) {
                    $latestSettingUpdate = Carbon::parse($rawMax);
                }
            }

            $shouldRegenerate = $generatedAt === null
                || ($latestRentalUpdate !== null && $latestRentalUpdate->greaterThan($generatedAt))
                || ($latestTemplateUpdate !== null && $latestTemplateUpdate->greaterThan($generatedAt))
                || ($latestSettingUpdate !== null && $latestSettingUpdate->greaterThan($generatedAt));

            if ($imageAvailable && ! $shouldRegenerate) {
                $raw = Storage::disk('local')->get($existingInvoice->png_path);
                if (is_string($raw) && $this->invoiceJpegLooksBroken($raw)) {
                    $shouldRegenerate = true;
                }
            }

            if ($pdfAvailable && $imageAvailable && ! $shouldRegenerate) {
                $urlVersion = (string) (optional($existingInvoice->generated_at)->timestamp ?? now()->timestamp);
                $signed = $this->buildExternalSignedDownloadUrl($rental, basename((string) $existingInvoice->pdf_path), $urlVersion);
                $signedImage = $this->buildExternalSignedDownloadUrl($rental, basename((string) $existingInvoice->png_path), $urlVersion);
                $signedView = $this->buildExternalSignedInvoiceViewUrl($rental);

                return [
                    'success' => true,
                    'signed_view_url' => $signedView,
                    'signed_url' => $signed,
                    'signed_image_url' => $signedImage,
                    'signed_png_url' => $signedImage,
                ];
            }
        }

        $rental->loadMissing(['outfits', 'handledBy']);
        $pricingSummary = $this->pricingSummary($rental);
        $storeLogoDataUri = $this->storeLogoDataUri($rental->store_id);
        $storePrimaryColor = $this->storeSettingString($rental->store_id, 'store_primary_color');
        $storeAdminPhone1 = $this->storeSettingString($rental->store_id, 'store_admin_phone_1')
            ?? $this->storeSettingString($rental->store_id, 'store_admin_phone')
            ?? $this->storeSettingString($rental->store_id, 'store_contact_phone');
        $storeAdminPhone2 = $this->storeSettingString($rental->store_id, 'store_admin_phone_2');
        $storeContactPhone = $storeAdminPhone1;
        $storeContactAddress = $this->storeSettingString($rental->store_id, 'store_contact_address');

        // Render invoice HTML
        $html = View::exists('invoices.template')
            ? view('invoices.template', compact('rental', 'pricingSummary', 'storeLogoDataUri', 'storePrimaryColor', 'storeAdminPhone1', 'storeAdminPhone2', 'storeContactPhone', 'storeContactAddress'))->render()
            : '<html><body><h1>Invoice</h1><p>No template found.</p></body></html>';

        // Generate PDF using available library
        $content = null;
        if (class_exists(Pdf::class)) {
            $pdf = Pdf::loadHtml($html);
            $content = $pdf->output();
        } elseif (class_exists(Dompdf::class)) {
            $dompdf = new Dompdf;
            $dompdf->loadHtml($html);
            $dompdf->render();
            $content = $dompdf->output();
        } else {
            return [
                'success' => false,
                'error' => 'Dompdf not installed. Run: composer require barryvdh/laravel-dompdf',
                'status' => 500,
            ];
        }

        $dir = "invoices/{$rental->id}";
        $token = now()->format('YmdHis').'-'.Str::random(6);
        $pdfFile = "invoice-{$token}.pdf";
        $jpgFile = "invoice-{$token}.jpg";
        Storage::disk('local')->put("{$dir}/{$pdfFile}", $content);

        $jpgPath = $this->buildPlaywrightInvoiceJpg($rental, $dir, $jpgFile);
        if (! $jpgPath) {
            $jpg = InvoiceImageRaster::fromPdfBlob($content);
            if (is_string($jpg) && $jpg !== '') {
                Storage::disk('local')->put("{$dir}/{$jpgFile}", $jpg);
                $jpgPath = "{$dir}/{$jpgFile}";
            } else {
                $jpgPath = $this->buildFallbackInvoiceJpg($rental, $dir, $jpgFile, $pricingSummary, $storeLogoDataUri);
            }
        }

        if ($jpgPath && Storage::disk('local')->exists($jpgPath)) {
            $raw = Storage::disk('local')->get($jpgPath);
            if (is_string($raw) && $this->invoiceJpegLooksBroken($raw)) {
                Storage::disk('local')->delete($jpgPath);
                $jpgPath = $this->buildFallbackInvoiceJpg($rental, $dir, $jpgFile, $pricingSummary, $storeLogoDataUri);
                $raw = $jpgPath && Storage::disk('local')->exists($jpgPath) ? Storage::disk('local')->get($jpgPath) : '';
            }
            $sized = $this->constrainInvoiceJpegMaxWidth($raw);
            if ($sized !== $raw) {
                Storage::disk('local')->put($jpgPath, $sized);
            }
        }

        $invoice = Invoice::create([
            'rental_id' => $rental->id,
            'pdf_path' => "{$dir}/{$pdfFile}",
            'png_path' => $jpgPath,
            'generated_at' => now(),
            'status' => 'generated',
        ]);

        $urlVersion = (string) now()->timestamp;
        $signed = $this->buildExternalSignedDownloadUrl($rental, $pdfFile, $urlVersion);
        $signedImage = null;
        if ($jpgPath) {
            $signedImage = $this->buildExternalSignedDownloadUrl($rental, basename((string) $jpgPath), $urlVersion);
        }
        $signedView = $this->buildExternalSignedInvoiceViewUrl($rental);

        return [
            'success' => true,
            'signed_view_url' => $signedView,
            'signed_url' => $signed,
            'signed_image_url' => $signedImage,
            // Backward-compatible key name used by existing UI script.
            'signed_png_url' => $signedImage,
        ];
    }

    private function invoiceTemplateLastModifiedAt(): ?Carbon
    {
        $files = [
            resource_path('views/invoices/template.blade.php'),
            resource_path('views/invoices/partials/note-invoice-inner.blade.php'),
            resource_path('views/invoices/partials/note-invoice-styles.blade.php'),
            app_path('Http/Controllers/InvoiceController.php'),
            app_path('Support/InvoiceImageRaster.php'),
        ];
        $max = null;
        foreach ($files as $file) {
            if (is_string($file) && $file !== '' && is_file($file)) {
                $t = @filemtime($file);
                if (is_int($t) && $t > 0) {
                    $max = $max === null ? $t : max($max, $t);
                }
            }
        }
        if ($max === null) {
            return null;
        }

        return Carbon::createFromTimestamp($max);
    }

    public function view(Rental $rental, Request $request)
    {
        if (! $request->hasValidSignature(false)) {
            abort(403);
        }

        $rental->loadMissing(['outfits', 'handledBy']);
        $pricingSummary = $this->pricingSummary($rental);
        $storeLogoDataUri = $this->storeLogoDataUri($rental->store_id);
        $storePrimaryColor = $this->storeSettingString($rental->store_id, 'store_primary_color');
        $storeAdminPhone1 = $this->storeSettingString($rental->store_id, 'store_admin_phone_1')
            ?? $this->storeSettingString($rental->store_id, 'store_admin_phone')
            ?? $this->storeSettingString($rental->store_id, 'store_contact_phone');
        $storeAdminPhone2 = $this->storeSettingString($rental->store_id, 'store_admin_phone_2');
        $storeContactPhone = $storeAdminPhone1;
        $storeContactAddress = $this->storeSettingString($rental->store_id, 'store_contact_address');

        $invoice = Invoice::where('rental_id', $rental->id)->latest('id')->first();
        if (! $invoice) {
            abort(404);
        }

        $pdfAvailable = $invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path);
        $imageAvailable = $invoice->png_path && Storage::disk('local')->exists($invoice->png_path);

        $urlVersion = (string) (optional($invoice->generated_at)->timestamp ?? now()->timestamp);
        $pdfUrl = $pdfAvailable ? $this->buildExternalSignedDownloadUrl($rental, basename((string) $invoice->pdf_path), $urlVersion) : null;
        $imageUrl = $imageAvailable ? $this->buildExternalSignedDownloadUrl($rental, basename((string) $invoice->png_path), $urlVersion) : null;
        $regenUrl = $this->buildExternalSignedInvoiceRegenerateUrl($rental);

        return view('invoices.show', [
            'rental' => $rental,
            'invoice' => $invoice,
            'pricingSummary' => $pricingSummary,
            'storeLogoDataUri' => $storeLogoDataUri,
            'storePrimaryColor' => $storePrimaryColor,
            'storeAdminPhone1' => $storeAdminPhone1,
            'storeAdminPhone2' => $storeAdminPhone2,
            'storeContactPhone' => $storeContactPhone,
            'storeContactAddress' => $storeContactAddress,
            'pdfUrl' => $pdfUrl,
            'imageUrl' => $imageUrl,
            'regenUrl' => $regenUrl,
            'ogUrl' => $request->fullUrl(),
            'ogImageUrl' => $imageUrl !== null ? WhatsappLinkSettings::absolutizeUrl($request, $imageUrl) : null,
            'companyName' => 'Demo Store One',
        ]);
    }

    public function debugView(Rental $rental, Request $request)
    {
        if (! (config('app.debug') || app()->environment('local'))) {
            abort(404);
        }

        if (! $request->user()) {
            abort(403);
        }

        $rental->loadMissing(['outfits', 'handledBy']);
        $pricingSummary = $this->pricingSummary($rental);
        $storeLogoDataUri = $this->storeLogoDataUri($rental->store_id);
        $storePrimaryColor = $this->storeSettingString($rental->store_id, 'store_primary_color');
        $storeAdminPhone1 = $this->storeSettingString($rental->store_id, 'store_admin_phone_1')
            ?? $this->storeSettingString($rental->store_id, 'store_admin_phone')
            ?? $this->storeSettingString($rental->store_id, 'store_contact_phone');
        $storeAdminPhone2 = $this->storeSettingString($rental->store_id, 'store_admin_phone_2');
        $storeContactPhone = $storeAdminPhone1;
        $storeContactAddress = $this->storeSettingString($rental->store_id, 'store_contact_address');

        $invoice = Invoice::where('rental_id', $rental->id)->latest('id')->first();
        if (! $invoice) {
            abort(404);
        }

        $pdfAvailable = $invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path);
        $imageAvailable = $invoice->png_path && Storage::disk('local')->exists($invoice->png_path);

        $urlVersion = (string) (optional($invoice->generated_at)->timestamp ?? now()->timestamp);
        $pdfUrl = $pdfAvailable ? $this->buildExternalSignedDownloadUrl($rental, basename((string) $invoice->pdf_path), $urlVersion) : null;
        $imageUrl = $imageAvailable ? $this->buildExternalSignedDownloadUrl($rental, basename((string) $invoice->png_path), $urlVersion) : null;
        $regenUrl = $this->buildExternalSignedInvoiceRegenerateUrl($rental);

        return view('invoices.show', [
            'rental' => $rental,
            'invoice' => $invoice,
            'pricingSummary' => $pricingSummary,
            'storeLogoDataUri' => $storeLogoDataUri,
            'storePrimaryColor' => $storePrimaryColor,
            'storeAdminPhone1' => $storeAdminPhone1,
            'storeAdminPhone2' => $storeAdminPhone2,
            'storeContactPhone' => $storeContactPhone,
            'storeContactAddress' => $storeContactAddress,
            'pdfUrl' => $pdfUrl,
            'imageUrl' => $imageUrl,
            'regenUrl' => $regenUrl,
            'ogUrl' => $request->fullUrl(),
            'ogImageUrl' => $imageUrl !== null ? WhatsappLinkSettings::absolutizeUrl($request, $imageUrl) : null,
            'companyName' => 'Demo Store One',
        ]);
    }

    public function debugTemplatePreview(Rental $rental, Request $request)
    {
        if (! (config('app.debug') || app()->environment('local'))) {
            abort(404);
        }

        if (! $request->user()) {
            abort(403);
        }

        $rental->loadMissing(['outfits', 'handledBy']);
        $pricingSummary = $this->pricingSummary($rental);
        $storeLogoDataUri = $this->storeLogoDataUri($rental->store_id);
        $storePrimaryColor = $this->storeSettingString($rental->store_id, 'store_primary_color');
        $storeAdminPhone1 = $this->storeSettingString($rental->store_id, 'store_admin_phone_1')
            ?? $this->storeSettingString($rental->store_id, 'store_admin_phone')
            ?? $this->storeSettingString($rental->store_id, 'store_contact_phone');
        $storeAdminPhone2 = $this->storeSettingString($rental->store_id, 'store_admin_phone_2');
        $storeContactPhone = $storeAdminPhone1;
        $storeContactAddress = $this->storeSettingString($rental->store_id, 'store_contact_address');

        return view('invoices.template', compact('rental', 'pricingSummary', 'storeLogoDataUri', 'storePrimaryColor', 'storeAdminPhone1', 'storeAdminPhone2', 'storeContactPhone', 'storeContactAddress'));
    }

    public function download(Rental $rental, Request $request)
    {
        if (! $request->hasValidSignature(false)) {
            abort(403);
        }

        $file = $request->query('file', 'invoice.pdf');
        $path = "invoices/{$rental->id}/{$file}";

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $name = basename($path);
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $cacheHeaders = [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ];

            return Storage::disk('local')->response($path, $name, [
                'Content-Disposition' => 'inline; filename="'.$name.'"',
                ...$cacheHeaders,
            ]);
        }

        return Storage::disk('local')->download($path);
    }

    public function templatePreview(Rental $rental, Request $request)
    {
        if (! $request->hasValidSignature(false)) {
            abort(403);
        }

        $rental->loadMissing(['outfits', 'handledBy']);
        $pricingSummary = $this->pricingSummary($rental);
        $storeLogoDataUri = $this->storeLogoDataUri($rental->store_id);
        $storePrimaryColor = $this->storeSettingString($rental->store_id, 'store_primary_color');
        $storeAdminPhone1 = $this->storeSettingString($rental->store_id, 'store_admin_phone_1')
            ?? $this->storeSettingString($rental->store_id, 'store_admin_phone')
            ?? $this->storeSettingString($rental->store_id, 'store_contact_phone');
        $storeAdminPhone2 = $this->storeSettingString($rental->store_id, 'store_admin_phone_2');
        $storeContactPhone = $storeAdminPhone1;
        $storeContactAddress = $this->storeSettingString($rental->store_id, 'store_contact_address');

        return view('invoices.template', compact('rental', 'pricingSummary', 'storeLogoDataUri', 'storePrimaryColor', 'storeAdminPhone1', 'storeAdminPhone2', 'storeContactPhone', 'storeContactAddress'));
    }

    private function storeSettingString(?int $storeId, string $key): ?string
    {
        if ($storeId === null) {
            return null;
        }

        $row = StoreSetting::query()->where('store_id', $storeId)->where('key', $key)->first();
        $raw = $row?->value !== null ? (string) $row->value : '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $value = is_string($decoded) ? trim($decoded) : trim($raw, "\" \t\n\r\0\x0B");

        return $value !== '' ? $value : null;
    }

    private function storeLogoDataUri(?int $storeId): ?string
    {
        $path = null;
        if ($storeId !== null) {
            $row = StoreSetting::query()->where('store_id', $storeId)->where('key', 'store_logo_path')->first();
            $raw = $row?->value !== null ? (string) $row->value : '';
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            $candidate = is_string($decoded) ? trim($decoded) : trim($raw, "\" \t\n\r\0\x0B");
            if ($candidate !== '') {
                $path = $candidate;
            }
        }

        if ($path === null || $path === '' || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $binary = Storage::disk('public')->get($path);
        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    public function sendWhatsapp(Rental $rental, Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->mayOperateRentTransactions()) {
            return response()->json(['error' => 'Management accounts cannot send invoices. Use an Admin account.'], 403);
        }

        $invoice = Invoice::where('rental_id', $rental->id)->latest('id')->first();
        if (! $invoice) {
            $generated = $this->generate($rental, $request);
            if ($generated instanceof JsonResponse && $generated->getStatusCode() >= 400) {
                $payload = $generated->getData(true);
                $message = is_array($payload) && ! empty($payload['error'])
                    ? $payload['error']
                    : 'Failed to auto-generate invoice.';

                return response()->json(['error' => $message], $generated->getStatusCode());
            }

            $invoice = Invoice::where('rental_id', $rental->id)->latest('id')->first();
        }

        if (! $invoice) {
            return response()->json([
                'error' => 'Invoice record is not available after generation. Please generate invoice first and retry.',
            ], 422);
        }

        $target = FonnteMediaSender::normalizeTargetPhone($rental->phone ?? '');
        if ($target === '') {
            return response()->json(['error' => 'Customer phone is empty.'], 422);
        }

        $invoiceSendMode = strtolower(trim((string) config('services.fonnte.invoice_send_mode', 'media')));
        if ($invoiceSendMode === 'link') {
            $signedView = WhatsappLinkSettings::absolutizeUrl($request, $this->buildExternalSignedInvoiceViewUrl($rental));
            // Wrap URL in angle brackets so WhatsApp consistently recognizes it as a clickable link
            $textMessage = "Invoice for {$rental->customer_name}\n\n<{$signedView}>";

            $result = FonnteMediaSender::sendTextMessage($target, $textMessage);

            if (! $result['success']) {
                $err = (string) ($result['error'] ?? '');
                $status = match (true) {
                    str_contains($err, 'not configured') => 500,
                    str_contains($err, 'Failed to connect') => 502,
                    str_contains($err, 'Failed to send WhatsApp') => 500,
                    default => 422,
                };
                $payload = ['error' => $result['error']];
                if (! empty($result['details'])) {
                    $payload['details'] = $result['details'];
                }

                return response()->json($payload, $status);
            }

            $invoice->update(['sent_at' => now(), 'status' => 'sent']);

            return response()->json([
                'success' => true,
                'target' => $target,
                'send_mode' => 'link',
                'signed_view_url' => $signedView,
                'media_mode' => $result['media_mode'],
                'provider_response' => $result['provider_response'],
            ]);
        }

        $hasImage = $invoice->png_path && Storage::disk('local')->exists($invoice->png_path);
        if (! $hasImage) {
            return response()->json([
                'error' => 'Invoice image preview is not available. Please re-generate invoice and try again.',
            ], 422);
        }

        $file = basename((string) $invoice->png_path);
        $signedImage = WhatsappLinkSettings::absolutizeUrl($request, $this->buildExternalSignedDownloadUrl($rental, $file, (string) now()->timestamp));
        $signedView = WhatsappLinkSettings::absolutizeUrl($request, $this->buildExternalSignedInvoiceViewUrl($rental));
        $message = "Invoice for {$rental->customer_name}\n{$signedView}";

        $uploadBinary = Storage::disk('local')->get($invoice->png_path);

        $result = FonnteMediaSender::sendMedia(
            $request,
            $target,
            $message,
            $signedImage,
            "invoice-{$rental->id}.jpg",
            $uploadBinary,
        );

        if (! $result['success']) {
            $err = (string) ($result['error'] ?? '');
            $status = match (true) {
                str_contains($err, 'not configured') => 500,
                str_contains($err, 'Failed to connect') => 502,
                str_contains($err, 'Failed to send WhatsApp') => 500,
                default => 422,
            };
            $payload = ['error' => $result['error']];
            if (! empty($result['details'])) {
                $payload['details'] = $result['details'];
            }

            return response()->json($payload, $status);
        }

        $invoice->update(['sent_at' => now(), 'status' => 'sent']);

        return response()->json([
            'success' => true,
            'target' => $target,
            'media_url' => $signedImage,
            'media_mode' => $result['media_mode'],
            'provider_response' => $result['provider_response'],
        ]);
    }

    /**
     * Last-resort invoice JPEG when Playwright and Imagick PDF raster fail.
     * Mirrors the note-paper template (green brand, dashed sections, Total in green).
     */
    private function buildFallbackInvoiceJpg(Rental $rental, string $dir, string $jpgFile, ?array $pricingSummary = null, ?string $storeLogoDataUri = null): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $pricingEnabled = StoreCache::get('pricing_enabled', false);
        $pricingSummary = $pricingSummary ?? $this->pricingSummary($rental);
        $rental->loadMissing(['handledBy', 'outfits']);

        $payLabel = match ($rental->payment_method ?? '') {
            'cash' => 'Cash',
            'transfer' => 'Transfer',
            'qris' => 'QRIS',
            'edc' => 'EDC',
            'other' => 'Other',
            default => $rental->payment_method ? Str::headline((string) $rental->payment_method) : null,
        };

        $padOuter = 28;
        $innerW = 664;
        $width = $padOuter * 2 + $innerW;
        $rowGap = 26;
        $baseH = 420 + count($pricingSummary['items']) * $rowGap;
        if ($pricingEnabled) {
            $subtotal = (float) ($pricingSummary['subtotal'] ?? 0);
            $rentalDiscountAmount = (float) ($pricingSummary['rental_discount_amount'] ?? 0);
            $taxAmount = (float) ($pricingSummary['tax_amount'] ?? 0);
            $overdueFeeAmount = (float) ($pricingSummary['overdue_fee_amount'] ?? 0);
            $overdueFeeRate = (float) ($pricingSummary['overdue_fee_rate'] ?? ($rental->overdue_fee_rate ?? 0));
            $overdueFeeDays = (int) ($pricingSummary['overdue_fee_days'] ?? ($rental->overdue_fee_days ?? 0));
            $grandTotal = (float) ($pricingSummary['grand_total'] ?? 0);
            $rentPaid = (float) ($pricingSummary['rent_amount_paid'] ?? ($rental->amount_paid ?? 0));
            $overduePaid = (float) ($pricingSummary['overdue_amount_paid'] ?? ($rental->overdue_amount_paid ?? 0));
            $overdueChangeAmount = (float) ($pricingSummary['overdue_change_amount'] ?? ($rental->overdue_change_amount ?? 0));
            $customerPaid = $rentPaid + $overduePaid + $overdueChangeAmount;
            $rentOnlyTotal = max(0.0, $grandTotal - $overdueFeeAmount);
            $rentChangeAmount = max(0.0, $rentPaid - $rentOnlyTotal);
            $overduePayMethodRaw = trim((string) ($pricingSummary['overdue_payment_method'] ?? ''));

            $summaryH = 0;
            $summaryH += 24;
            if ($rentalDiscountAmount > 0.009) {
                $summaryH += 24;
            }
            $summaryH += 24;
            $summaryH += $taxAmount > 0.009 ? 30 : 6;
            if ((float) ($pricingSummary['extra_charge'] ?? 0) > 0.009) {
                $summaryH += 24;
            }
            if ($overdueFeeAmount > 0.009) {
                $summaryH += 24;
                if ($overdueFeeDays > 0) {
                    $summaryH += 24;
                }
                if ($overdueFeeRate > 0.009) {
                    $summaryH += 24;
                }
            }
            $summaryH += 36;
            if ($payLabel) {
                $summaryH += 24;
            }
            if ($overdueFeeAmount > 0.009 && $overduePayMethodRaw !== '') {
                $summaryH += 24;
            }
            if ($rentPaid > 0.009) {
                $summaryH += 24;
            }
            if ($overduePaid > 0.009) {
                $summaryH += 24;
            }
            if ($rentChangeAmount > 0.009) {
                $summaryH += 24;
            }
            if ($overdueChangeAmount > 0.009) {
                $summaryH += 24;
            }
            if ($customerPaid > 0.009 && ($overdueFeeAmount > 0.009 || $overduePaid > 0.009 || $overdueChangeAmount > 0.009)) {
                $summaryH += 24;
            }
            $summaryH += 30;
            $summaryH += 52;

            $baseH += max(560, $summaryH + 360);
        } else {
            $baseH += 120;
        }
        // No practical outfit-rental receipt needs more than this; the cap only guards
        // against a corrupted pricing summary blowing up memory, not real content.
        $height = min(9000, max(960, $baseH));

        $im = imagecreatetruecolor($width, $height);
        if ($im === false) {
            return null;
        }

        $bgPage = imagecolorallocate($im, 241, 245, 249);
        $white = imagecolorallocate($im, 255, 255, 255);
        $border = imagecolorallocate($im, 226, 232, 240);
        $brand = imagecolorallocate($im, 22, 101, 52);
        $ink = imagecolorallocate($im, 15, 23, 42);
        $muted = imagecolorallocate($im, 100, 116, 139);
        $dash = imagecolorallocate($im, 203, 213, 225);
        $rule = imagecolorallocate($im, 229, 231, 235);

        imagefilledrectangle($im, 0, 0, $width, $height, $bgPage);

        $cardX = $padOuter;
        $cardY = $padOuter;
        $cardW = $innerW;
        $cardH = $height - 2 * $padOuter;
        imagefilledrectangle($im, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, $white);
        imagerectangle($im, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, $border);

        $x = $cardX + 44;
        $right = $cardX + $cardW - 44;
        $y = $cardY + 36;

        $font = $this->invoiceRasterSansFont();
        $brandName = Str::upper($rental->store?->name ?? 'DEMO STORE ONE');
        $logo = null;
        if (is_string($storeLogoDataUri) && $storeLogoDataUri !== '' && str_contains($storeLogoDataUri, 'base64,')) {
            $raw = substr($storeLogoDataUri, strpos($storeLogoDataUri, 'base64,') + 7);
            $bin = base64_decode((string) $raw, true);
            if (is_string($bin) && $bin !== '' && function_exists('imagecreatefromstring')) {
                $img = @imagecreatefromstring($bin);
                if ($img !== false) {
                    $logo = $img;
                }
            }
        }

        if ($font !== null && function_exists('imagettftext') && function_exists('imagettfbbox')) {
            $y = $this->invoiceJpgDrawNotePaperTtf(
                $im,
                $font,
                $x,
                $right,
                $y,
                $brand,
                $ink,
                $muted,
                $dash,
                $rule,
                $brandName,
                $rental,
                $pricingEnabled,
                $pricingSummary,
                $payLabel,
                $logo
            );
        } else {
            $y = $this->invoiceJpgDrawNotePaperBitmap(
                $im,
                $x,
                $right,
                $y,
                $brand,
                $ink,
                $muted,
                $dash,
                $rule,
                $brandName,
                $rental,
                $pricingEnabled,
                $pricingSummary,
                $payLabel,
                $logo
            );
        }

        ob_start();
        $jpegWritten = imagejpeg($im, null, 90);
        $jpegBinary = ob_get_clean();
        imagedestroy($im);
        if ($logo instanceof \GdImage) {
            imagedestroy($logo);
        }

        if ($jpegWritten === false || ! is_string($jpegBinary) || $jpegBinary === '') {
            return null;
        }

        $storagePath = "{$dir}/{$jpgFile}";
        Storage::disk('local')->put($storagePath, $jpegBinary);

        return $storagePath;
    }

    private function invoiceRasterSansFont(): ?string
    {
        foreach ([
            // Ships with the dompdf dependency this app already requires, so it's
            // guaranteed present on any server (shared hosting included) — unlike
            // the system font paths below, which vary by OS and may not exist.
            base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/Library/Fonts/Arial.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
        ] as $path) {
            if (is_string($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  resource|\GdImage  $im
     */
    private function invoiceJpgDrawDashedLine($im, int $x1, int $x2, int $y, int $color): void
    {
        for ($x = $x1; $x < $x2; $x += 10) {
            imageline($im, $x, $y, min($x + 5, $x2), $y, $color);
        }
    }

    /**
     * @param  resource|\GdImage  $im
     * @return int Next baseline Y (approx)
     */
    private function invoiceJpgDrawNotePaperTtf(
        $im,
        string $font,
        int $x,
        int $right,
        int $y,
        int $brand,
        int $ink,
        int $muted,
        int $dash,
        int $rule,
        string $brandName,
        Rental $rental,
        bool $pricingEnabled,
        array $pricingSummary,
        ?string $payLabel,
        \GdImage|null $logo = null
    ): int {
        $cx = (int) (($x + $right) / 2);

        if ($logo instanceof \GdImage && function_exists('imagecopyresampled')) {
            $lw = imagesx($logo);
            $lh = imagesy($logo);
            if ($lw > 0 && $lh > 0) {
                $maxW = 110;
                $maxH = 64;
                $scale = min($maxW / $lw, $maxH / $lh, 1);
                $dw = (int) max(1, round($lw * $scale));
                $dh = (int) max(1, round($lh * $scale));
                $dx = $cx - (int) round($dw / 2);
                imagecopyresampled($im, $logo, $dx, $y, 0, 0, $dw, $dh, $lw, $lh);
                $y += $dh + 18;
            }
        }

        $sizeTitle = 30;
        $box = imagettfbbox($sizeTitle, 0, $font, $brandName);
        $tw = (int) abs($box[2] - $box[0]);
        imagettftext($im, $sizeTitle, 0, $cx - (int) ($tw / 2), $y + $sizeTitle, $brand, $font, $brandName);
        $y += 52;

        $sub = 'Rent Receipt';
        $box = imagettfbbox(18, 0, $font, $sub);
        $tw = (int) abs($box[2] - $box[0]);
        imagettftext($im, 18, 0, $cx - (int) ($tw / 2), $y + 18, $ink, $font, $sub);
        $y += 36;

        $meta = 'Rent #'.$rental->id.' • '.now()->format('d M Y H:i');
        $box = imagettfbbox(13, 0, $font, $meta);
        $tw = (int) abs($box[2] - $box[0]);
        imagettftext($im, 13, 0, $cx - (int) ($tw / 2), $y + 13, $muted, $font, $meta);
        $y += 36;

        $this->invoiceJpgDrawDashedLine($im, $x, $right, $y, $dash);
        $y += 28;

        $lh = 11;
        imagettftext($im, $lh, 0, $x, $y + $lh, $muted, $font, 'CUSTOMER');
        $y += 22;
        imagettftext($im, 17, 0, $x, $y + 17, $ink, $font, (string) ($rental->customer_name ?: '-'));
        $y += 28;
        imagettftext($im, 13, 0, $x, $y + 13, $muted, $font, (string) ($rental->phone ?: '-'));
        $y += 36;

        if ($rental->handledBy) {
            $hb = $rental->handledBy;
            $hbRoleLabel = $hb->role ? Str::headline($hb->role) : '';
            $hbShowRole = $hbRoleLabel !== '' && strcasecmp(trim((string) $hb->name), $hbRoleLabel) !== 0;
            $this->invoiceJpgDrawDashedLine($im, $x, $right, $y, $dash);
            $y += 28;
            imagettftext($im, $lh, 0, $x, $y + $lh, $muted, $font, 'HANDLED BY');
            $y += 22;
            imagettftext($im, 17, 0, $x, $y + 17, $ink, $font, (string) $hb->name);
            $y += 28;
            if ($hbShowRole) {
                imagettftext($im, 13, 0, $x, $y + 13, $muted, $font, $hbRoleLabel);
                $y += 28;
            }
            $y += 8;
        }

        $this->invoiceJpgDrawDashedLine($im, $x, $right, $y, $dash);
        $y += 28;
        imagettftext($im, $lh, 0, $x, $y + $lh, $muted, $font, 'ITEMS');
        $y += 26;

        $colPrice = $right - 8;
        imagettftext($im, 11, 0, $x, $y + 11, $muted, $font, 'ITEM CODE');
        $box = imagettfbbox(11, 0, $font, 'PRICE');
        $pw = (int) abs($box[2] - $box[0]);
        imagettftext($im, 11, 0, $colPrice - $pw, $y + 11, $muted, $font, 'PRICE');
        $y += 18;
        imageline($im, $x, $y, $right, $y, $rule);
        $y += 22;

        foreach ($rental->outfits as $outfit) {
            $code = (string) ($outfit->new_code ?: $outfit->code);
            imagettftext($im, 14, 0, $x, $y + 14, $ink, $font, $code);
            $priceStr = '–';
            if ($pricingEnabled) {
                $itemPrice = (float) ($outfit->price ?? 0);
                $itemDisc = (float) ($outfit->pivot->discount ?? 0);
                $itemDiscType = (string) ($outfit->pivot->discount_type ?? 'fixed');
                $eff = $itemDisc > 0
                    ? ($itemDiscType === 'percent'
                        ? $itemPrice - ($itemPrice * $itemDisc / 100)
                        : $itemPrice - $itemDisc)
                    : $itemPrice;
                $eff = max(0, $eff);
                $priceStr = $eff > 0 ? 'Rp '.number_format($eff, 0, ',', '.') : '–';
            }
            $box = imagettfbbox(14, 0, $font, $priceStr);
            $pw = (int) abs($box[2] - $box[0]);
            imagettftext($im, 14, 0, $colPrice - $pw, $y + 14, $ink, $font, $priceStr);
            $y += 22;
            imageline($im, $x, $y, $right, $y, $rule);
            $y += 16;
        }

        if ($pricingEnabled) {
            $subtotal = (float) ($pricingSummary['subtotal'] ?? 0);
            $rentalDiscountAmount = (float) ($pricingSummary['rental_discount_amount'] ?? 0);
            $overdueFeeAmount = (float) ($pricingSummary['overdue_fee_amount'] ?? 0);
            $overdueFeeRate = (float) ($pricingSummary['overdue_fee_rate'] ?? ($rental->overdue_fee_rate ?? 0));
            $overdueFeeDays = (int) ($pricingSummary['overdue_fee_days'] ?? ($rental->overdue_fee_days ?? 0));
            $taxPercent = (float) ($pricingSummary['tax_percent'] ?? 0);
            $taxAmount = (float) ($pricingSummary['tax_amount'] ?? 0);
            $grandTotal = (float) ($pricingSummary['grand_total'] ?? 0);
            $preTaxNet = (float) ($pricingSummary['pre_tax_net'] ?? max(0, $subtotal - $rentalDiscountAmount));
            $rentPaid = (float) ($pricingSummary['rent_amount_paid'] ?? ($rental->amount_paid ?? 0));
            $overduePaid = (float) ($pricingSummary['overdue_amount_paid'] ?? ($rental->overdue_amount_paid ?? 0));
            $overdueChangeAmount = (float) ($pricingSummary['overdue_change_amount'] ?? ($rental->overdue_change_amount ?? 0));
            $overdueCashPaid = $overduePaid + $overdueChangeAmount;
            $amountPaid = $rentPaid + $overduePaid;
            $customerPaid = $rentPaid + $overduePaid + $overdueChangeAmount;
            $rentOnlyTotal = max(0.0, $grandTotal - $overdueFeeAmount);
            $rentChangeAmount = max(0.0, $rentPaid - $rentOnlyTotal);
            $balanceDue = max(0.0, $grandTotal - $amountPaid);
            $rentalDays = (int) ($pricingSummary['rental_days'] ?? 1);

            $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, 'Subtotal (after line discounts)', 'Rp '.number_format($subtotal, 0, ',', '.'));
            $y += 24;
            if ($rentalDiscountAmount > 0) {
                $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, 'Order discount', '-Rp '.number_format($rentalDiscountAmount, 0, ',', '.'));
                $y += 24;
            }
            $extraChargeJpg = (float) ($pricingSummary['extra_charge'] ?? 0);
            $extraDaysJpg = (int) ($pricingSummary['extra_days'] ?? 0);
            if ($extraChargeJpg > 0.009) {
                $extraLabel = 'Additional charge'.($extraDaysJpg > 0 ? ' ('.number_format($extraDaysJpg, 0, ',', '.').' '.($extraDaysJpg === 1 ? 'day' : 'days').')' : '');
                $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, $extraLabel, 'Rp '.number_format($extraChargeJpg, 0, ',', '.'));
                $y += 24;
            }
            if ($overdueFeeAmount > 0.009) {
                $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, 'Overdue fine', 'Rp '.number_format($overdueFeeAmount, 0, ',', '.'));
                $y += 24;
                if ($overdueFeeDays <= 0 && $overdueFeeRate > 0.009 && $overdueFeeAmount > 0.009) {
                    $overdueFeeDays = (int) max(1, round($overdueFeeAmount / $overdueFeeRate));
                }
                if ($overdueFeeDays > 0) {
                    $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, 'Overdue days', number_format(max(1, $overdueFeeDays), 0, ',', '.').' '.(max(1, $overdueFeeDays) === 1 ? 'day' : 'days'));
                    $y += 24;
                }
                if ($overdueFeeRate > 0.009) {
                    $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, 'Overdue rate', 'Rp '.number_format($overdueFeeRate, 0, ',', '.').'/day');
                    $y += 24;
                }
            }

            $totalStr = 'Rp '.number_format($grandTotal, 0, ',', '.');
            imagettftext($im, 17, 0, $x, $y + 17, $ink, $font, 'Total due');
            $box = imagettfbbox(18, 0, $font, $totalStr);
            $pw = (int) abs($box[2] - $box[0]);
            imagettftext($im, 18, 0, $colPrice - $pw, $y + 18, $brand, $font, $totalStr);
            $y += 36;

            if ($payLabel) {
                $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, 'Payment method', $payLabel);
                $y += 24;
            }
            $overduePayMethodRaw = (string) ($pricingSummary['overdue_payment_method'] ?? '');
            $overduePayLabel = match ($overduePayMethodRaw) {
                'cash' => 'Cash',
                'transfer' => 'Transfer',
                'qris' => 'QRIS',
                'edc' => 'EDC',
                'other' => 'Other',
                default => $overduePayMethodRaw !== '' ? Str::headline($overduePayMethodRaw) : null,
            };
            if ($overdueFeeAmount > 0.009 && $overduePayLabel) {
                $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, 'Overdue payment method', $overduePayLabel);
                $y += 24;
            }
            if ($rentPaid > 0.009) {
                $isDownPmt = ($rental->payment_kind ?? '') === 'down_payment';
                $rentPaidLabel = $isDownPmt ? 'Down payment paid' : (($rental->payment_kind ?? '') === 'final_payment' ? 'Final payment' : 'Rent paid');
                $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, $rentPaidLabel, 'Rp '.number_format($rentPaid, 0, ',', '.'));
                $y += 24;
                $rentOnlyTotalTtf = max(0.0, $grandTotal - $overdueFeeAmount);
                $rentBalanceTtf = max(0.0, $rentOnlyTotalTtf - $rentPaid);
                if ($isDownPmt && $rentBalanceTtf > 0.009) {
                    $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, 'Remaining balance', 'Rp '.number_format($rentBalanceTtf, 0, ',', '.'));
                    $y += 24;
                }
            }
            if ($overduePaid > 0.009) {
                $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, 'Overdue paid', 'Rp '.number_format($overdueCashPaid, 0, ',', '.'));
                $y += 24;
            }
            $changeAmtTtf = max(0.0, $customerPaid - $grandTotal);
            if ($rentChangeAmount > 0.009 && abs($rentChangeAmount - $changeAmtTtf) > 0.009) {
                $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, 'Rent change (cash to return)', 'Rp '.number_format($rentChangeAmount, 0, ',', '.'));
                $y += 24;
            }
            if ($overdueChangeAmount > 0.009) {
                $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, 'Overdue change (cash to return)', 'Rp '.number_format($overdueChangeAmount, 0, ',', '.'));
                $y += 24;
            }
            if ($customerPaid > 0.009 && ($overdueFeeAmount > 0.009 || $overduePaid > 0.009 || $overdueChangeAmount > 0.009)) {
                $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, 'Customer paid', 'Rp '.number_format($customerPaid, 0, ',', '.'));
                $y += 24;
            }
            $changeAmt = max(0.0, $customerPaid - $grandTotal);
            if ($balanceDue > 0.009) {
                $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $muted, 'Balance due', 'Rp '.number_format($balanceDue, 0, ',', '.'));
            } elseif ($changeAmt > 0.009) {
                $this->invoiceJpgTtfPair($im, $font, $x, $colPrice, $y, $brand, 'Total change (cash to return)', 'Rp '.number_format($changeAmt, 0, ',', '.'));
            }
            $y += 30;
            imagettftext($im, 13, 0, $x, $y + 13, $muted, $font, 'Rent period');
            $y += 20;
            $calDaysTtf = max(1, $rentalDays + $extraDaysJpg);
            $period = Carbon::parse($rental->start_date)->format('d M Y').' – '.Carbon::parse($rental->end_date)->format('d M Y').' · '.number_format($calDaysTtf, 0, ',', '.').' '.($calDaysTtf === 1 ? 'day' : 'days');
            imagettftext($im, 14, 0, $x, $y + 14, $ink, $font, $period);
        } else {
            imagettftext($im, 13, 0, $x, $y + 13, $muted, $font, 'Rent period');
            $y += 20;
            $period = Carbon::parse($rental->start_date)->format('d M Y').' – '.Carbon::parse($rental->end_date)->format('d M Y');
            imagettftext($im, 14, 0, $x, $y + 14, $ink, $font, $period);
            $y += 36;
            $totalStr = 'Rp '.number_format(0, 0, ',', '.');
            imagettftext($im, 17, 0, $x, $y + 17, $ink, $font, 'Total');
            $box = imagettfbbox(18, 0, $font, $totalStr);
            $pw = (int) abs($box[2] - $box[0]);
            imagettftext($im, 18, 0, $colPrice - $pw, $y + 18, $brand, $font, $totalStr);
        }

        return $y;
    }

    /**
     * @param  resource|\GdImage  $im
     */
    private function invoiceJpgTtfPair($im, string $font, int $x, int $colPrice, int $y, int $muted, string $left, string $rightText): void
    {
        imagettftext($im, 13, 0, $x, $y + 13, $muted, $font, $left);
        $box = imagettfbbox(13, 0, $font, $rightText);
        $pw = (int) abs($box[2] - $box[0]);
        imagettftext($im, 13, 0, $colPrice - $pw, $y + 13, $muted, $font, $rightText);
    }

    /**
     * Bitmap-font fallback (no TTF): same structure, approximate styling.
     *
     * @param  resource|\GdImage  $im
     */
    private function invoiceJpgDrawNotePaperBitmap(
        $im,
        int $x,
        int $right,
        int $y,
        int $brand,
        int $ink,
        int $muted,
        int $dash,
        int $rule,
        string $brandName,
        Rental $rental,
        bool $pricingEnabled,
        array $pricingSummary,
        ?string $payLabel,
        \GdImage|null $logo = null
    ): int {
        $cx = (int) (($x + $right) / 2);
        if ($logo instanceof \GdImage && function_exists('imagecopyresampled')) {
            $lw = imagesx($logo);
            $lh = imagesy($logo);
            if ($lw > 0 && $lh > 0) {
                $maxW = 110;
                $maxH = 64;
                $scale = min($maxW / $lw, $maxH / $lh, 1);
                $dw = (int) max(1, round($lw * $scale));
                $dh = (int) max(1, round($lh * $scale));
                $dx = $cx - (int) round($dw / 2);
                imagecopyresampled($im, $logo, $dx, $y, 0, 0, $dw, $dh, $lw, $lh);
                $y += $dh + 14;
            }
        }
        $f = 5;
        $tw = strlen($brandName) * 9;
        imagestring($im, $f, max($x, $cx - (int) ($tw / 2)), $y, substr($brandName, 0, 42), $brand);
        $y += 34;
        $sub = 'Rent Receipt';
        imagestring($im, 4, max($x, $cx - (int) (strlen($sub) * 5.5)), $y, $sub, $ink);
        $y += 28;
        $meta = 'Rent #'.$rental->id.' • '.now()->format('d M Y H:i');
        imagestring($im, 3, max($x, $cx - (int) (strlen($meta) * 4.5)), $y, substr($meta, 0, 60), $muted);
        $y += 32;
        $this->invoiceJpgDrawDashedLine($im, $x, $right, $y, $dash);
        $y += 16;
        imagestring($im, 2, $x, $y, 'CUSTOMER', $muted);
        $y += 18;
        imagestring($im, 4, $x, $y, substr((string) ($rental->customer_name ?: '-'), 0, 48), $ink);
        $y += 22;
        imagestring($im, 3, $x, $y, substr((string) ($rental->phone ?: '-'), 0, 48), $muted);
        $y += 28;

        if ($rental->handledBy) {
            $hb = $rental->handledBy;
            $this->invoiceJpgDrawDashedLine($im, $x, $right, $y, $dash);
            $y += 16;
            imagestring($im, 2, $x, $y, 'HANDLED BY', $muted);
            $y += 18;
            imagestring($im, 3, $x, $y, substr((string) $hb->name, 0, 48), $ink);
            $y += 26;
        }

        $this->invoiceJpgDrawDashedLine($im, $x, $right, $y, $dash);
        $y += 16;
        imagestring($im, 2, $x, $y, 'ITEMS', $muted);
        $y += 20;
        imagestring($im, 2, $x, $y, 'ITEM CODE', $muted);
        imagestring($im, 2, $right - 72, $y, 'PRICE', $muted);
        $y += 18;
        imageline($im, $x, $y, $right, $y, $rule);
        $y += 14;

        foreach ($rental->outfits as $outfit) {
            $code = substr((string) ($outfit->new_code ?: $outfit->code), 0, 36);
            imagestring($im, 3, $x, $y, $code, $ink);
            $priceStr = '–';
            if ($pricingEnabled) {
                $itemPrice = (float) ($outfit->price ?? 0);
                $itemDisc = (float) ($outfit->pivot->discount ?? 0);
                $itemDiscType = (string) ($outfit->pivot->discount_type ?? 'fixed');
                $eff = $itemDisc > 0
                    ? ($itemDiscType === 'percent'
                        ? $itemPrice - ($itemPrice * $itemDisc / 100)
                        : $itemPrice - $itemDisc)
                    : $itemPrice;
                $eff = max(0, $eff);
                $priceStr = $eff > 0 ? 'Rp '.number_format($eff, 0, ',', '.') : '–';
            }
            imagestring($im, 3, $right - 140, $y, substr($priceStr, 0, 18), $ink);
            $y += 20;
            imageline($im, $x, $y, $right, $y, $rule);
            $y += 12;
        }

        if ($pricingEnabled) {
            $subtotal = (float) ($pricingSummary['subtotal'] ?? 0);
            $rentalDiscountAmount = (float) ($pricingSummary['rental_discount_amount'] ?? 0);
            $taxPercent = (float) ($pricingSummary['tax_percent'] ?? 0);
            $taxAmount = (float) ($pricingSummary['tax_amount'] ?? 0);
            $overdueFeeAmount = (float) ($pricingSummary['overdue_fee_amount'] ?? 0);
            $overdueFeeRate = (float) ($pricingSummary['overdue_fee_rate'] ?? ($rental->overdue_fee_rate ?? 0));
            $overdueFeeDays = (int) ($pricingSummary['overdue_fee_days'] ?? ($rental->overdue_fee_days ?? 0));
            $grandTotal = (float) ($pricingSummary['grand_total'] ?? 0);
            $preTaxNet = (float) ($pricingSummary['pre_tax_net'] ?? max(0, $subtotal - $rentalDiscountAmount));
            $rentPaid = (float) ($pricingSummary['rent_amount_paid'] ?? ($rental->amount_paid ?? 0));
            $overduePaid = (float) ($pricingSummary['overdue_amount_paid'] ?? ($rental->overdue_amount_paid ?? 0));
            $overdueChangeAmount = (float) ($pricingSummary['overdue_change_amount'] ?? ($rental->overdue_change_amount ?? 0));
            $overdueCashPaid = $overduePaid + $overdueChangeAmount;
            $amountPaid = $rentPaid + $overduePaid;
            $customerPaid = $rentPaid + $overduePaid + $overdueChangeAmount;
            $rentOnlyTotal = max(0.0, $grandTotal - $overdueFeeAmount);
            $rentChangeAmount = max(0.0, $rentPaid - $rentOnlyTotal);
            $balanceDue = max(0.0, $grandTotal - $amountPaid);
            $rentalDays = (int) ($pricingSummary['rental_days'] ?? 1);

            imagestring($im, 3, $x, $y, 'Subtotal', $muted);
            imagestring($im, 3, $right - 140, $y, 'Rp '.number_format($subtotal, 0, ',', '.'), $muted);
            $y += 20;
            if ($rentalDiscountAmount > 0) {
                imagestring($im, 3, $x, $y, 'Order discount', $muted);
                imagestring($im, 3, $right - 140, $y, '-Rp '.number_format($rentalDiscountAmount, 0, ',', '.'), $muted);
                $y += 20;
            }
            $extraChargeBmp = (float) ($pricingSummary['extra_charge'] ?? 0);
            $extraDaysBmp = (int) ($pricingSummary['extra_days'] ?? 0);
            if ($extraChargeBmp > 0.009) {
                imagestring($im, 3, $x, $y, 'Additional charge'.($extraDaysBmp > 0 ? ' ('.$extraDaysBmp.($extraDaysBmp === 1 ? ' day' : ' days').')' : ''), $muted);
                imagestring($im, 3, $right - 140, $y, 'Rp '.number_format($extraChargeBmp, 0, ',', '.'), $muted);
                $y += 20;
            }
            if ($overdueFeeAmount > 0.009) {
                imagestring($im, 3, $x, $y, 'Overdue fine', $muted);
                imagestring($im, 3, $right - 140, $y, 'Rp '.number_format($overdueFeeAmount, 0, ',', '.'), $muted);
                $y += 20;
                if ($overdueFeeDays <= 0 && $overdueFeeRate > 0.009 && $overdueFeeAmount > 0.009) {
                    $overdueFeeDays = (int) max(1, round($overdueFeeAmount / $overdueFeeRate));
                }
                if ($overdueFeeDays > 0) {
                    imagestring($im, 3, $x, $y, 'Overdue days', $muted);
                    imagestring($im, 3, $right - 140, $y, number_format(max(1, $overdueFeeDays), 0, ',', '.').' '.(max(1, $overdueFeeDays) === 1 ? 'day' : 'days'), $muted);
                    $y += 20;
                }
                if ($overdueFeeRate > 0.009) {
                    imagestring($im, 3, $x, $y, 'Overdue rate', $muted);
                    imagestring($im, 3, $right - 140, $y, 'Rp '.number_format($overdueFeeRate, 0, ',', '.').'/day', $muted);
                    $y += 20;
                }
            }
            imagestring($im, 4, $x, $y, 'Total due', $ink);
            imagestring($im, 5, $right - 160, $y, 'Rp '.number_format($grandTotal, 0, ',', '.'), $brand);
            $y += 28;
            if ($payLabel) {
                imagestring($im, 3, $x, $y, 'Payment method', $muted);
                imagestring($im, 3, $right - 140, $y, substr($payLabel, 0, 24), $muted);
                $y += 18;
            }
            $overduePayMethodRaw = (string) ($pricingSummary['overdue_payment_method'] ?? '');
            $overduePayLabel = match ($overduePayMethodRaw) {
                'cash' => 'Cash',
                'transfer' => 'Transfer',
                'qris' => 'QRIS',
                'edc' => 'EDC',
                'other' => 'Other',
                default => $overduePayMethodRaw !== '' ? Str::headline($overduePayMethodRaw) : null,
            };
            if ($overdueFeeAmount > 0.009 && $overduePayLabel) {
                imagestring($im, 3, $x, $y, 'Overdue pay method', $muted);
                imagestring($im, 3, $right - 140, $y, substr((string) $overduePayLabel, 0, 24), $muted);
                $y += 18;
            }
            if ($rentPaid > 0.009) {
                $isDownPmtBmp = ($rental->payment_kind ?? '') === 'down_payment';
                $rentPaidLabel = $isDownPmtBmp ? 'Down payment paid' : (($rental->payment_kind ?? '') === 'final_payment' ? 'Final payment' : 'Rent paid');
                imagestring($im, 3, $x, $y, substr($rentPaidLabel, 0, 24), $muted);
                imagestring($im, 3, $right - 140, $y, 'Rp '.number_format($rentPaid, 0, ',', '.'), $muted);
                $y += 18;
                $rentOnlyTotalBmp = max(0.0, $grandTotal - $overdueFeeAmount);
                $rentBalanceBmp = max(0.0, $rentOnlyTotalBmp - $rentPaid);
                if ($isDownPmtBmp && $rentBalanceBmp > 0.009) {
                    imagestring($im, 3, $x, $y, 'Remaining balance', $muted);
                    imagestring($im, 3, $right - 140, $y, 'Rp '.number_format($rentBalanceBmp, 0, ',', '.'), $muted);
                    $y += 18;
                }
            }
            if ($overduePaid > 0.009) {
                imagestring($im, 3, $x, $y, 'Overdue paid', $muted);
                imagestring($im, 3, $right - 140, $y, 'Rp '.number_format($overdueCashPaid, 0, ',', '.'), $muted);
                $y += 18;
            }
            $changeAmtBmp = max(0.0, $customerPaid - $grandTotal);
            if ($rentChangeAmount > 0.009 && abs($rentChangeAmount - $changeAmtBmp) > 0.009) {
                imagestring($im, 3, $x, $y, 'Rent change', $muted);
                imagestring($im, 3, $right - 140, $y, 'Rp '.number_format($rentChangeAmount, 0, ',', '.'), $muted);
                $y += 18;
            }
            if ($overdueChangeAmount > 0.009) {
                imagestring($im, 3, $x, $y, 'Overdue change', $muted);
                imagestring($im, 3, $right - 140, $y, 'Rp '.number_format($overdueChangeAmount, 0, ',', '.'), $muted);
                $y += 18;
            }
            if ($customerPaid > 0.009 && ($overdueFeeAmount > 0.009 || $overduePaid > 0.009 || $overdueChangeAmount > 0.009)) {
                imagestring($im, 3, $x, $y, 'Customer paid', $muted);
                imagestring($im, 3, $right - 140, $y, 'Rp '.number_format($customerPaid, 0, ',', '.'), $muted);
                $y += 18;
            }
            $changeAmt = max(0.0, $customerPaid - $grandTotal);
            if ($balanceDue > 0.009) {
                imagestring($im, 3, $x, $y, 'Balance', $muted);
                imagestring($im, 3, $right - 140, $y, 'Rp '.number_format($balanceDue, 0, ',', '.'), $muted);
            } elseif ($changeAmt > 0.009) {
                imagestring($im, 3, $x, $y, 'Total change', $brand);
                imagestring($im, 3, $right - 140, $y, 'Rp '.number_format($changeAmt, 0, ',', '.'), $brand);
            }
            $y += 26;
            imagestring($im, 3, $x, $y, 'Rent period', $muted);
            $y += 18;
            $calDaysBmp = max(1, $rentalDays + $extraDaysBmp);
            $period = Carbon::parse($rental->start_date)->format('d M Y').' - '.Carbon::parse($rental->end_date)->format('d M Y').' · '.number_format($calDaysBmp, 0, ',', '.').' '.($calDaysBmp === 1 ? 'day' : 'days');
            imagestring($im, 3, $x, $y, substr($period, 0, 62), $ink);
        } else {
            imagestring($im, 3, $x, $y, 'Rent period', $muted);
            $y += 18;
            $period = Carbon::parse($rental->start_date)->format('d M Y').' - '.Carbon::parse($rental->end_date)->format('d M Y');
            imagestring($im, 3, $x, $y, substr($period, 0, 48), $ink);
            $y += 26;
            imagestring($im, 4, $x, $y, 'Total', $ink);
            imagestring($im, 5, $right - 120, $y, 'Rp 0', $brand);
        }

        return $y;
    }

    /**
     * Downscale invoice JPEG for WhatsApp / “Open image” so width matches the narrower note-paper template.
     */
    private function constrainInvoiceJpegMaxWidth(string $jpegBinary): string
    {
        $max = max(400, min(2400, (int) env('INVOICE_JPEG_MAX_WIDTH', 1080)));
        if ($max <= 0) {
            return $jpegBinary;
        }

        if (class_exists(\Imagick::class)) {
            try {
                $im = new \Imagick;
                $im->readImageBlob($jpegBinary);
                $w = $im->getImageWidth();
                if ($w > $max) {
                    $im->resizeImage($max, 0, \Imagick::FILTER_LANCZOS, 1, true);
                }
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(93);
                $out = $im->getImageBlob();
                $im->clear();

                return is_string($out) && $out !== '' ? $out : $jpegBinary;
            } catch (\Throwable) {
                // fall through
            }
        }

        if (function_exists('imagecreatefromstring') && function_exists('imagescale')) {
            $img = @imagecreatefromstring($jpegBinary);
            if ($img !== false) {
                $w = imagesx($img);
                if ($w > $max) {
                    $h = max(1, (int) round(imagesy($img) * ($max / $w)));
                    $scaled = imagescale($img, $max, $h);
                    imagedestroy($img);
                    $img = $scaled !== false ? $scaled : false;
                }
                if ($img !== false) {
                    ob_start();
                    imagejpeg($img, null, 93);
                    $out = ob_get_clean();
                    imagedestroy($img);
                    if (is_string($out) && $out !== '') {
                        return $out;
                    }
                }
            }
        }

        return $jpegBinary;
    }

    private function buildPlaywrightInvoiceJpg(Rental $rental, string $dir, string $jpgFile): ?string
    {
        $renderer = strtolower(trim((string) env('INVOICE_IMAGE_RENDERER', '')));
        if ($renderer !== 'playwright') {
            return null;
        }
        if (app()->runningUnitTests()) {
            return null;
        }

        if (! \function_exists('proc_open')) {
            Log::warning('Playwright invoice screenshot skipped: proc_open() is not available (disabled on host).', [
                'rental_id' => $rental->id,
            ]);

            return null;
        }

        $playwrightLocalBin = base_path('node_modules/.bin/playwright');
        $playwrightLocalBinCmd = $playwrightLocalBin.'.cmd';
        if (! is_file($playwrightLocalBin) && ! is_file($playwrightLocalBinCmd)) {
            Log::warning('Playwright invoice screenshot skipped: playwright is not installed. Run npm install.', [
                'rental_id' => $rental->id,
            ]);

            return null;
        }

        $previewUrl = $this->absoluteUrlForPlaywrightScreenshot($this->buildExternalSignedTemplatePreviewUrl($rental));
        if ($previewUrl === '' || ! preg_match('#^https?://#i', $previewUrl)) {
            Log::warning('Playwright invoice screenshot skipped: template preview URL is not absolute. Set APP_URL (and optionally WHATSAPP_MEDIA_BASE_URL) to your public/ngrok https origin.', [
                'rental_id' => $rental->id,
                'preview_url' => $previewUrl,
            ]);

            return null;
        }

        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $tmpPngPath = rtrim($tmpDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'invoice-preview-'.$rental->id.'-'.uniqid('', true).'.png';

        $viewport = preg_replace('/\s+/', '', trim((string) env('INVOICE_PLAYWRIGHT_VIEWPORT', '520,4000')));
        if (! preg_match('/^\d+,\d+$/', $viewport)) {
            $viewport = '520,4000';
        }
        $waitMs = max(2000, min(120000, (int) env('INVOICE_PLAYWRIGHT_WAIT_MS', 12000)));

        $script = base_path('scripts/invoice-screenshot.mjs');
        if (! is_file($script)) {
            Log::warning('Playwright invoice screenshot skipped: scripts/invoice-screenshot.mjs is missing.', [
                'rental_id' => $rental->id,
            ]);

            return null;
        }

        $args = [
            'node',
            $script,
            $previewUrl,
            $tmpPngPath,
            '#invoice-print-target .note-paper',
            $viewport,
            (string) $waitMs,
        ];

        $defaultTimeout = max(60, (int) ceil($waitMs / 1000) + 90);
        $processTimeout = max(10, min(300, (int) env('INVOICE_PLAYWRIGHT_PROCESS_TIMEOUT_SECONDS', $defaultTimeout)));
        $process = new Process($args, base_path());
        $process->setTimeout($processTimeout);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($tmpPngPath)) {
            Log::warning('Playwright invoice screenshot failed, falling back.', [
                'rental_id' => $rental->id,
                'exit_code' => $process->getExitCode(),
                'output' => trim($process->getErrorOutput()."\n".$process->getOutput()),
            ]);

            return null;
        }

        try {
            $jpgBinary = InvoiceImageRaster::fromPngFileWithoutTrim($tmpPngPath);
            if (! is_string($jpgBinary) || $jpgBinary === '') {
                return null;
            }

            $storagePath = "{$dir}/{$jpgFile}";
            Storage::disk('local')->put($storagePath, $jpgBinary);

            return $storagePath;
        } catch (\Throwable $e) {
            Log::warning('Playwright JPG conversion failed, falling back.', [
                'rental_id' => $rental->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        } finally {
            if (is_file($tmpPngPath)) {
                @unlink($tmpPngPath);
            }
        }
    }

    /**
     * Playwright runs in a subprocess and needs an absolute http(s) URL. Prefer WHATSAPP_MEDIA_BASE_URL, else APP_URL.
     */
    private function absoluteUrlForPlaywrightScreenshot(string $signedPathOrUrl): string
    {
        $signedPathOrUrl = trim($signedPathOrUrl);
        if ($signedPathOrUrl === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $signedPathOrUrl)) {
            return $signedPathOrUrl;
        }
        $explicit = trim((string) env('INVOICE_PLAYWRIGHT_BASE_URL', ''));
        if ($explicit !== '') {
            return Str::finish($explicit, '/').ltrim($signedPathOrUrl, '/');
        }
        $base = WhatsappLinkSettings::publicBaseUrl();
        if ($base === '') {
            $base = rtrim((string) config('app.url'), '/');
        }
        if ($base === '') {
            return $signedPathOrUrl;
        }

        return Str::finish($base, '/').ltrim($signedPathOrUrl, '/');
    }

    private function buildExternalSignedDownloadUrl(Rental $rental, string $file, ?string $version = null): string
    {
        $signedDays = WhatsappLinkSettings::signedUrlDays();
        $publicBaseUrl = WhatsappLinkSettings::publicBaseUrl();
        $params = [
            'rental' => $rental->id,
            'file' => $file,
        ];
        if ($version !== null && $version !== '') {
            $params['v'] = $version;
        }
        // Must match Route::middleware('signed:relative'): signature is over path + query only.
        $signed = $signedDays > 0
            ? URL::temporarySignedRoute('invoices.download', now()->addDays($signedDays), $params, absolute: false)
            : URL::signedRoute('invoices.download', $params, absolute: false);

        if ($publicBaseUrl === '') {
            return $signed;
        }

        $parts = parse_url($signed);
        if (! is_array($parts) || empty($parts['path'])) {
            return $signed;
        }

        $pathWithQuery = $parts['path'].(isset($parts['query']) ? ('?'.$parts['query']) : '');

        return Str::finish($publicBaseUrl, '/').ltrim($pathWithQuery, '/');
    }

    private function buildExternalSignedInvoiceViewUrl(Rental $rental): string
    {
        $signedDays = WhatsappLinkSettings::signedUrlDays();
        $publicBaseUrl = WhatsappLinkSettings::publicBaseUrl();
        $signed = $signedDays > 0
            ? URL::temporarySignedRoute('invoices.view', now()->addDays($signedDays), [
                'rental' => $rental->id,
            ], absolute: false)
            : URL::signedRoute('invoices.view', [
                'rental' => $rental->id,
            ], absolute: false);

        if ($publicBaseUrl === '') {
            return $signed;
        }

        $parts = parse_url($signed);
        if (! is_array($parts) || empty($parts['path'])) {
            return $signed;
        }

        $pathWithQuery = $parts['path'].(isset($parts['query']) ? ('?'.$parts['query']) : '');

        return Str::finish($publicBaseUrl, '/').ltrim($pathWithQuery, '/');
    }

    private function buildExternalSignedInvoiceRegenerateUrl(Rental $rental): string
    {
        $signedDays = WhatsappLinkSettings::signedUrlDays();
        $publicBaseUrl = WhatsappLinkSettings::publicBaseUrl();
        $signed = $signedDays > 0
            ? URL::temporarySignedRoute('invoices.regenerate', now()->addDays($signedDays), [
                'rental' => $rental->id,
            ], absolute: false)
            : URL::signedRoute('invoices.regenerate', [
                'rental' => $rental->id,
            ], absolute: false);

        if ($publicBaseUrl === '') {
            return $signed;
        }

        $parts = parse_url($signed);
        if (! is_array($parts) || empty($parts['path'])) {
            return $signed;
        }

        $pathWithQuery = $parts['path'].(isset($parts['query']) ? ('?'.$parts['query']) : '');

        return Str::finish($publicBaseUrl, '/').ltrim($pathWithQuery, '/');
    }

    private function buildExternalSignedTemplatePreviewUrl(Rental $rental): string
    {
        $signedDays = WhatsappLinkSettings::signedUrlDays();
        $publicBaseUrl = WhatsappLinkSettings::publicBaseUrl();
        $signed = $signedDays > 0
            ? URL::temporarySignedRoute('invoices.template-preview', now()->addDays($signedDays), [
                'rental' => $rental->id,
            ], absolute: false)
            : URL::signedRoute('invoices.template-preview', [
                'rental' => $rental->id,
            ], absolute: false);

        if ($publicBaseUrl === '') {
            return $signed;
        }

        $parts = parse_url($signed);
        if (! is_array($parts) || empty($parts['path'])) {
            return $signed;
        }

        $pathWithQuery = $parts['path'].(isset($parts['query']) ? ('?'.$parts['query']) : '');

        return Str::finish($publicBaseUrl, '/').ltrim($pathWithQuery, '/');
    }
}

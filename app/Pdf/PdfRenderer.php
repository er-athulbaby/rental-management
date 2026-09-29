<?php

namespace App\Pdf;

use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/** Blade → HTML → PDF with mPDF (spec §9.2). Returns the PDF bytes; the caller stores them privately. */
class PdfRenderer
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array{header?: string, footer?: string, watermark?: string|null}  $chrome
     */
    public function render(string $view, array $data = [], array $chrome = []): string
    {
        $mpdf = $this->make();

        if (isset($chrome['header'])) {
            $mpdf->SetHTMLHeader($chrome['header']);
        }
        if (isset($chrome['footer'])) {
            $mpdf->SetHTMLFooter($chrome['footer']); // may contain {PAGENO} and {nbpg}
        }
        if (! empty($chrome['watermark'])) {
            $mpdf->SetWatermarkText($chrome['watermark'], 0.08);
            $mpdf->showWatermarkText = true;
        }

        $mpdf->WriteHTML(view($view, $data)->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public function make(): Mpdf
    {
        $tempDir = storage_path('app/mpdf'); // font cache; must be writable; outside app/private so it isn't backed up
        File::ensureDirectoryExists($tempDir);

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 28,
            'margin_bottom' => 22,
            'margin_header' => 8,
            'margin_footer' => 8,
            'tempDir' => $tempDir,
            'fontDir' => [...(new ConfigVariables)->getDefaults()['fontDir'], resource_path('fonts')],
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + [
                'arabic' => [
                    'R' => 'IBMPlexSansArabic-Regular.ttf',
                    'B' => 'IBMPlexSansArabic-Bold.ttf',
                    'useOTL' => 0xFF,   // without OpenType layout Arabic letters don't join
                    'useKashida' => 75, // kashida for justified Arabic
                ],
            ],
            'default_font' => 'dejavusans',
            // 1 = never shrink for width (0 is silently clamped to 1). A single row taller than a page
            // still scales the WHOLE table, hence one row per clause paragraph (spec §9.2).
            'shrink_tables_to_fit' => 1,
            'whitelistStreamWrappers' => ['file'], // no http(s) fetches from HTML
        ]);
    }
}

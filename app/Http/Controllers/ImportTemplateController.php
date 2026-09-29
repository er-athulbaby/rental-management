<?php

namespace App\Http\Controllers;

use App\Enums\ImportKind;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ImportTemplateController
{
    // ponytail: header row only; Excel's Text format on the ID/phone/IBAN/money columns is set by hand
    // (openspout styles cells, not empty columns). RunImport refuses numbers in those columns either way.
    public function __invoke(ImportKind $kind): BinaryFileResponse
    {
        $path = sys_get_temp_dir().'/rms-template-'.Str::uuid().'.xlsx';
        SimpleExcelWriter::create($path)->addHeader($kind->headers())->close();

        return response()->download($path, "{$kind->value}-template.xlsx")->deleteFileAfterSend();
    }
}

<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Billing\OwnerLedger;
use App\Billing\OwnerStatementCalculator;
use App\Models\OwnerStatement;
use App\Support\Fils;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Spec §10: statements export to Excel. Audited (§8.4). */
final class OwnerStatementExportController
{
    public function __invoke(Request $request, OwnerStatement $statement): BinaryFileResponse
    {
        abort_unless($request->user()?->can('view', $statement), 403);
        Audit::log('owner_statement.exported', $statement, properties: ['format' => 'xlsx'], causer: $request->user());

        $f = OwnerStatementCalculator::compute($statement);
        $path = sys_get_temp_dir().'/rms-statement-'.Str::uuid().'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        $balance = $f['opening'];
        $writer->addRow(['Date' => '', 'Entry' => 'Opening balance', 'Reference' => '', 'Amount' => '', 'Balance' => Fils::toDecimal($balance)]);
        foreach ($f['entries'] as $e) {
            $balance += $e['amount'];
            $writer->addRow(['Date' => $e['date'], 'Entry' => OwnerLedger::kindLabel($e['kind']), 'Reference' => $e['reference'], 'Amount' => Fils::toDecimal($e['amount']), 'Balance' => Fils::toDecimal($balance)]);
        }
        $writer->addRow(['Date' => '', 'Entry' => 'Management fee', 'Reference' => 'on '.Fils::toDecimal($f['fee_base']), 'Amount' => Fils::toDecimal(-$f['fee']), 'Balance' => '']);
        $writer->addRow(['Date' => '', 'Entry' => 'VAT on the fee', 'Reference' => '', 'Amount' => Fils::toDecimal(-$f['fee_tax']), 'Balance' => '']);
        $writer->addRow(['Date' => '', 'Entry' => 'Closing balance', 'Reference' => '', 'Amount' => '', 'Balance' => Fils::toDecimal($f['closing'])]);
        $writer->close();

        return response()->download($path, ($statement->number ?? 'statement-draft').'.xlsx')->deleteFileAfterSend();
    }
}

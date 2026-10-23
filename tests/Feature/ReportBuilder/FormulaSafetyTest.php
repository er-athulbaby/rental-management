<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Reports\Builder\ExcelWriter;
use App\Reports\Packages\PackageResult;
use App\Reports\Packages\PackageSheet;
use App\Reports\Packages\PackageWorkbook;
use App\Support\SafeCells;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use Spatie\SimpleExcel\SimpleExcelWriter;

/** All sheet XML of the file. The readers rebuild a formula from any "=" text, so the raw XML is the proof. */
function sheetXml(string $path): string
{
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_starts_with($zip->getNameIndex($i), 'xl/worksheets/')) {
            $xml .= $zip->getFromIndex($i);
        }
    }

    return $xml;
}

function formulaCount(string $path): int
{
    return preg_match_all('/<f[ >]/', sheetXml($path));
}

/** The inline text of every string cell. */
function cellTexts(string $path): array
{
    preg_match_all('#<t[^>]*>(.*?)</t>#', sheetXml($path), $m);

    return array_map(fn (string $s) => html_entity_decode($s, ENT_QUOTES), $m[1]);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->finance = matrixUser(RoleName::Finance, Building::factory()->create());
});

test('a builder export keeps text and a custom field label that start with = or @ as text, never formulas', function () {
    $field = customField('customer', 'text', '=X');
    $a = Customer::factory()->create(['name_en' => '=1+1']);
    $b = Customer::factory()->create(['name_en' => '@SUM(A1)']);
    customValue($field, $a, ['value_text' => '=HYPERLINK("http://evil","click")']);
    $result = runReport($this->finance, ['subject' => 'tenants', 'columns' => ['name', 'cf_x'], 'sort' => ['field' => 'name', 'dir' => 'asc']]);
    $path = sys_get_temp_dir().'/fs-'.Str::uuid().'.xlsx';

    ExcelWriter::write($result, $path);

    expect(formulaCount($path))->toBe(0)
        ->and(cellTexts($path))->toContain('=X', '=1+1', '@SUM(A1)', '=HYPERLINK("http://evil","click")');
});

test('real numbers and money strings keep the cell type they had', function () {
    $path = sys_get_temp_dir().'/fs-'.Str::uuid().'.xlsx';
    $writer = SimpleExcelWriter::create($path)->noHeaderRow();
    $writer->addRow(SafeCells::row([12, 1.5, '-12.500', '1,250.000', '=1+1', '', null, 'plain']));
    $writer->close();

    $cells = SafeCells::row([12, 1.5, '-12.500', '1,250.000', '=1+1', '', null, 'plain'])->getCells();
    expect($cells[0])->toBeInstanceOf(NumericCell::class)
        ->and($cells[1])->toBeInstanceOf(NumericCell::class)
        ->and($cells[2])->toBeInstanceOf(StringCell::class)->and($cells[2]->getValue())->toBe('-12.500')
        ->and($cells[3])->toBeInstanceOf(StringCell::class)->and($cells[3]->getValue())->toBe('1,250.000')
        ->and($cells[4])->toBeInstanceOf(StringCell::class)->and($cells[4]->getValue())->toBe('=1+1')
        ->and($cells[4])->not->toBeInstanceOf(FormulaCell::class)
        ->and(formulaCount($path))->toBe(0)
        ->and(xlsxRows($path)[0])->toBe([12, 1.5, '-12.500', '1,250.000', null, '', '', 'plain']) // the reader itself rebuilds a formula from any "=" text; cellTexts shows the file
        ->and(cellTexts($path))->toContain('=1+1');
});

test('a report package keeps titles, notes and rows that start with = as text', function () {
    $path = sys_get_temp_dir().'/fs-'.Str::uuid().'.xlsx';
    $sheet = new PackageSheet('=Evil', 'P', ['=Col', 'Amount'], [['=2+2', '-12.500'], ['@x', 7.5]], 2);

    PackageWorkbook::write(new PackageResult('P', [$sheet, PackageSheet::skip('=Gone', '=Reason')]), $path);

    expect(formulaCount($path))->toBe(0)
        ->and(cellTexts($path))->toContain('=Evil', '=Col', '=2+2', '@x', '-12.500', '=Gone', '=Reason');
});

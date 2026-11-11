<?php

namespace App\Services;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export rekening koran ke Excel (PhpSpreadsheet).
 * Semua teks ditulis sebagai string eksplisit agar isi yang diawali "=" tidak dieksekusi sebagai rumus.
 */
final class StatementXlsx
{
    private const NUMBER_FORMAT = '#,##0';

    public function download(Statement $statement): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();

        $this->mutasiSheet($spreadsheet->getActiveSheet(), $statement);
        $this->ringkasanSheet($spreadsheet->createSheet(), $statement);
        $spreadsheet->setActiveSheetIndex(0);

        $name = 'rekening-koran-'
            . Str::slug($statement->branch?->kode ?? 'konsolidasi') . '-'
            . $statement->from->format('Ymd') . '-' . $statement->to->format('Ymd') . '.xlsx';

        return response()->streamDownload(
            function () use ($spreadsheet): void {
                (new Xlsx($spreadsheet))->save('php://output');
            },
            $name,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private function mutasiSheet(Worksheet $sheet, Statement $s): void
    {
        $sheet->setTitle('Rekening koran');
        $showBranch = $s->branch === null;

        $this->put($sheet, 1, 1, 'Laporan Rekening Koran');
        $sheet->getStyle([1, 1])->getFont()->setBold(true)->setSize(14);
        $this->put($sheet, 1, 2, 'Cabang: ' . ($s->branch?->nama ?? 'Semua cabang (konsolidasi)'));
        $this->put($sheet, 1, 3, 'Periode: ' . $s->from->format('d M Y') . ' - ' . $s->to->format('d M Y'));

        $headers = $showBranch
            ? ['Tanggal dan jam', 'Cabang', 'Pos', 'Keterangan', 'Masuk', 'Keluar', 'Saldo']
            : ['Tanggal dan jam', 'Pos', 'Keterangan', 'Masuk', 'Keluar', 'Saldo'];
        $lastCol = count($headers);
        $numFrom = $lastCol - 2; // kolom Masuk

        foreach ($headers as $i => $header) {
            $this->put($sheet, $i + 1, 5, $header);
        }
        $sheet->getStyle([1, 5, $lastCol, 5])->getFont()->setBold(true);

        $row = 6;
        $labelCol = $showBranch ? 4 : 3;

        $this->put($sheet, 1, $row, $s->from->format('d/m/Y H:i'));
        $this->put($sheet, $labelCol, $row, 'Saldo awal');
        $this->put($sheet, $lastCol, $row, $s->opening);

        foreach ($s->rows as $r) {
            $row++;
            $col = 1;
            $this->put($sheet, $col++, $row, $r['waktu']->format('d/m/Y H:i'));
            if ($showBranch) {
                $this->put($sheet, $col++, $row, $r['cabang']);
            }
            $this->put($sheet, $col++, $row, $r['pos']);
            $this->put($sheet, $col++, $row, $r['keterangan']);
            $this->put($sheet, $col++, $row, $r['masuk']);
            $this->put($sheet, $col++, $row, $r['keluar']);
            $this->put($sheet, $col, $row, $r['saldo']);
        }

        $row++;
        $this->put($sheet, $labelCol, $row, 'Total');
        $this->put($sheet, $numFrom, $row, $s->totalMasuk);
        $this->put($sheet, $numFrom + 1, $row, $s->totalKeluar);
        $sheet->getStyle([1, $row, $lastCol, $row])->getFont()->setBold(true);

        $row++;
        $this->put($sheet, $labelCol, $row, 'Saldo akhir');
        $this->put($sheet, $lastCol, $row, $s->closing);
        $sheet->getStyle([1, $row, $lastCol, $row])->getFont()->setBold(true);

        $sheet->getStyle([$numFrom, 6, $lastCol, $row])->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);

        for ($c = 1; $c <= $lastCol; $c++) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
    }

    private function ringkasanSheet(Worksheet $sheet, Statement $s): void
    {
        $sheet->setTitle('Ringkasan');

        $this->put($sheet, 1, 1, 'Ringkasan per pos (cash flow)');
        $sheet->getStyle([1, 1])->getFont()->setBold(true)->setSize(14);

        foreach (['Pos', 'Masuk', 'Keluar', 'Neto'] as $i => $h) {
            $this->put($sheet, $i + 1, 3, $h);
        }
        $sheet->getStyle([1, 3, 4, 3])->getFont()->setBold(true);

        $row = 3;
        foreach ($s->perPos as $p) {
            $row++;
            $this->put($sheet, 1, $row, $p['pos']);
            $this->put($sheet, 2, $row, $p['masuk']);
            $this->put($sheet, 3, $row, $p['keluar']);
            $this->put($sheet, 4, $row, $p['masuk'] - $p['keluar']);
        }

        $row++;
        $this->put($sheet, 1, $row, 'Total');
        $this->put($sheet, 2, $row, $s->totalMasuk);
        $this->put($sheet, 3, $row, $s->totalKeluar);
        $this->put($sheet, 4, $row, $s->totalMasuk - $s->totalKeluar);
        $sheet->getStyle([1, $row, 4, $row])->getFont()->setBold(true);

        if ($s->perBranch !== []) {
            $row += 2;
            $this->put($sheet, 1, $row, 'Ringkasan per cabang');
            $sheet->getStyle([1, $row])->getFont()->setBold(true);
            $row++;
            foreach (['Cabang', 'Saldo awal', 'Masuk', 'Keluar', 'Saldo akhir'] as $i => $h) {
                $this->put($sheet, $i + 1, $row, $h);
            }
            $sheet->getStyle([1, $row, 5, $row])->getFont()->setBold(true);

            foreach ($s->perBranch as $b) {
                $row++;
                $this->put($sheet, 1, $row, $b['cabang']);
                $this->put($sheet, 2, $row, $b['awal']);
                $this->put($sheet, 3, $row, $b['masuk']);
                $this->put($sheet, 4, $row, $b['keluar']);
                $this->put($sheet, 5, $row, $b['akhir']);
            }
        }

        $sheet->getStyle([2, 4, 5, $row])->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);

        for ($c = 1; $c <= 5; $c++) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
    }

    private function put(Worksheet $sheet, int $col, int $row, string|int $value): void
    {
        if (is_int($value)) {
            $sheet->setCellValue([$col, $row], $value);

            return;
        }

        $sheet->setCellValueExplicit([$col, $row], $value, DataType::TYPE_STRING);
    }
}

<?php

namespace App\Exports;

use DateTimeImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

final class XlsxReportWriter
{
    public function write(iterable $sheets, string $path): int
    {
        $options = new Options();
        $options->SHOULD_CREATE_NEW_SHEETS_AUTOMATICALLY = false;
        $writer = new Writer($options);
        $writer->openToFile($path);
        $total = 0;
        $sheetCount = 0;
        try {
            foreach ($sheets as $definition) {
                $columns = $definition["columns"];
                if (!$columns || count($columns) > 16384) {
                    throw new RuntimeException("Invalid Excel columns.");
                }
                $sheet =
                    $sheetCount++ === 0
                    ? $writer->getCurrentSheet()
                    : $writer->addNewSheetAndMakeItCurrent();
                $sheet->setName($definition["name"]);
                $sheet->setSheetView((new SheetView())->setFreezeRow(2));
                $header = (new Style())
                    ->setFontBold()
                    ->setBackgroundColor("F3F4F6");
                $cells = [];
                foreach ($columns as $index => $column) {
                    $sheet->setColumnWidth(
                        (float) ($column["width"] ?? 24),
                        $index + 1,
                    );
                    $cells[] = new StringCell($column["label"], $header);
                }
                $writer->addRow(new Row($cells));
                $rowNumber = 1;
                foreach ($definition["rows"] as $row) {
                    if (++$rowNumber > 1048576) {
                        throw new RuntimeException(
                            "Excel sheet row limit exceeded.",
                        );
                    }
                    $cells = [];
                    foreach ($columns as $column) {
                        if (!array_key_exists($column["key"], $row)) {
                            throw new RuntimeException(
                                "Missing export column.",
                            );
                        }
                        $cells[] = $this->cell(
                            $row[$column["key"]],
                            $column["type"] ?? "text",
                        );
                    }
                    $writer->addRow(new Row($cells));
                    $total++;
                }
                $sheet->setAutoFilter(
                    new AutoFilter(0, 1, count($columns) - 1, $rowNumber),
                );
            }
            if ($sheetCount === 0) {
                throw new RuntimeException("Report has no sheets.");
            }
        } finally {
            $writer->close();
        }
        return $total;
    }

    private function cell(mixed $value, string $type): Cell
    {
        if ($value === null) {
            return new EmptyCell(null, null);
        }
        if ($type === "date") {
            $date = DateTimeImmutable::createFromFormat(
                "!Y-m-d",
                (string) $value,
            );
            if (!$date || $date->format("Y-m-d") !== (string) $value) {
                throw new RuntimeException("Invalid export date.");
            }
            return new DateTimeCell(
                $date,
                (new Style())->setFormat("dd/mm/yyyy"),
            );
        }
        if (in_array($type, ["integer", "decimal", "decimal4"], true)) {
            $text = (string) $value;
            $pattern = match ($type) {
                "integer" => '/^-?[0-9]+$/D',
                "decimal4" => '/^-?[0-9]+(?:\.[0-9]{1,4})?$/D',
                default => '/^-?[0-9]+(?:\.[0-9]{1,2})?$/D',
            };
            if (!preg_match($pattern, $text)) {
                throw new RuntimeException("Invalid export number.");
            }
            // Excel only preserves 15 significant digits in numeric cells.
            $digits = ltrim(str_replace(["-", "."], "", $text), "0");
            if (strlen($digits) > 15) {
                return new StringCell($text, null);
            }
            return new NumericCell(
                $type === "integer" ? (int) $text : (float) $text,
                (new Style())->setFormat(
                    match ($type) {
                        "integer" => "#,##0",
                        "decimal4" => "#,##0.0000;[Red]-#,##0.0000",
                        default => "#,##0.##;[Red]-#,##0.##",
                    },
                ),
            );
        }
        if ($type !== "text" || !is_scalar($value)) {
            throw new RuntimeException("Invalid export cell type.");
        }
        // StringCell keeps text beginning with =, +, - or @ as literal text.
        return new StringCell((string) $value, null);
    }
}

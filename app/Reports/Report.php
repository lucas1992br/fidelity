<?php

namespace App\Reports;

use App\Settings\ClinicSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report of the Relatórios screen (US-11.2). Each report exposes a single
 * query (or data set) read by both its Excel export and its PDF view, so the
 * two files always show the same rows. Reports only hold scalar filters and
 * dates, so their exports can be serialized onto the queue.
 */
abstract class Report
{
    public const string FilterPeriod = 'period';

    public const string FilterPlan = 'plan';

    public const string FilterStatus = 'status';

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public ?int $planId = null,
        public ?string $status = null,
    ) {}

    /**
     * Identifier used in the screen, in the PDF view name and in the file name.
     */
    abstract public static function key(): string;

    abstract public static function title(): string;

    abstract public static function description(): string;

    /**
     * Filters the report understands, among the Filter* constants.
     *
     * @return list<string>
     */
    abstract public static function filters(): array;

    /**
     * Options of the status filter: value => label.
     *
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [];
    }

    /**
     * Number of rows the Excel file will have; decides whether it is queued.
     */
    abstract public function rowCount(): int;

    /**
     * Variables of the PDF view, besides `report` and `clinic`.
     *
     * @return array<string, mixed>
     */
    abstract public function pdfData(): array;

    /**
     * The Excel export (app/Exports) reading this report's query.
     */
    abstract public function export(): Export;

    /**
     * Blade view of the PDF, under resources/views/pdf/reports.
     */
    abstract public function pdfView(): string;

    /**
     * Paper orientation of the PDF.
     */
    public function pdfOrientation(): string
    {
        return 'landscape';
    }

    /**
     * The PDF rendered on demand, streamed as a download.
     */
    public function downloadPdf(): StreamedResponse
    {
        $pdf = Pdf::loadView($this->pdfView(), [
            ...$this->pdfData(),
            'report' => $this,
            'clinic' => app(ClinicSettings::class),
        ])->setPaper('a4', $this->pdfOrientation());

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $this->fileName('pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Whether the spreadsheet is too large to be generated during the request.
     */
    public function shouldQueueExcel(): bool
    {
        return $this->rowCount() > (int) config('reports.queue_after_rows');
    }

    public function downloadExcel(): BinaryFileResponse
    {
        return Excel::download($this->export(), $this->fileName('xlsx'));
    }

    /**
     * Queue the spreadsheet into the exports disk and return its path there.
     */
    public function queueExcel(): string
    {
        $path = Str::beforeLast($this->fileName('xlsx'), '.xlsx').'-'.Str::lower(Str::random(8)).'.xlsx';

        Excel::queue($this->export(), $path, config('reports.exports_disk'));

        return $path;
    }

    /**
     * Period shown in the headers: "01/10/2026 a 31/10/2026", or the issue
     * date for snapshot reports that do not filter by period.
     */
    public function periodLabel(): string
    {
        if (! in_array(self::FilterPeriod, static::filters(), true)) {
            return 'Posição em '.today()->format('d/m/Y');
        }

        return $this->from->format('d/m/Y').' a '.$this->until->format('d/m/Y');
    }

    /**
     * Download name, e.g. "relatorio-pagamentos-2026-10-05.xlsx".
     */
    public function fileName(string $extension): string
    {
        return 'relatorio-'.static::key().'-'.today()->format('Y-m-d').".{$extension}";
    }

    /**
     * The six reports, keyed by their identifier, in the order of the screen.
     *
     * @return array<string, class-string<Report>>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach ([CustomersReport::class, InvoicesReport::class, DelinquencyReport::class, PaymentsReport::class, EntriesReport::class, CashFlowReportExport::class] as $report) {
            $catalog[$report::key()] = $report;
        }

        return $catalog;
    }
}

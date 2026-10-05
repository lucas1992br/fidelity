<?php

namespace App\Http\Controllers;

use App\Exports\DelinquentsExport;
use App\Queries\DelinquentsQuery;
use App\Settings\ClinicSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class DelinquentsExportController extends Controller
{
    /**
     * Download the delinquents list as a PDF (US-8.5).
     */
    public function pdf(DelinquentsQuery $delinquents, ClinicSettings $settings): Response
    {
        Gate::authorize('billing.view');

        return Pdf::loadView('pdf.reports.delinquents', [
            'delinquents' => $delinquents->get(),
            'summary' => $delinquents->summary(),
            'clinic' => $settings,
        ])->setPaper('a4', 'landscape')->download($this->fileName('pdf'));
    }

    /**
     * Download the delinquents list as an Excel spreadsheet (US-8.5).
     */
    public function excel(DelinquentsQuery $delinquents, ClinicSettings $settings): Response
    {
        Gate::authorize('billing.view');

        return Excel::download(new DelinquentsExport($delinquents, $settings), $this->fileName('xlsx'));
    }

    protected function fileName(string $extension): string
    {
        return 'inadimplentes-'.today()->format('Y-m-d').".{$extension}";
    }
}

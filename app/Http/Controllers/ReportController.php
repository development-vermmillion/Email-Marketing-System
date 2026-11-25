<?php

namespace App\Http\Controllers;

use App\Enums\RecipientStatus;
use App\Exports\RecipientsExport;
use App\Http\Requests\ReportFilterRequest;
use App\Models\Campaign;
use App\Services\ReportService;
use Illuminate\View\View;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index(ReportFilterRequest $request): View
    {
        $filters = $request->validated();
        $campaignQuery = $this->reports->campaignsQuery($filters);

        $totals = (clone $campaignQuery)->reorder()->selectRaw('
            COALESCE(SUM(total_recipients),0) AS total, COALESCE(SUM(queued_count),0) AS queued, COALESCE(SUM(sent_count),0) AS sent,
            COALESCE(SUM(delivered_count),0) AS delivered, COALESCE(SUM(opened_count),0) AS opened, COALESCE(SUM(clicked_count),0) AS clicked,
            COALESCE(SUM(bounced_count),0) AS bounced, COALESCE(SUM(failed_count),0) AS failed,
            COALESCE(SUM(complained_count),0) AS complained, COALESCE(SUM(unsubscribed_count),0) AS unsubscribed
        ')->first();

        $base = $totals->delivered > 0 ? $totals->delivered : $totals->sent;
        $pct = fn ($n, $d) => $d > 0 ? round($n / $d * 100, 2) : 0;

        return view('reports.index', [
            'filters' => $filters,
            'campaignOptions' => Campaign::orderByDesc('id')->limit(200)->get(['id', 'name']),
            'campaigns' => $campaignQuery->paginate(10, ['*'], 'campaigns')->withQueryString(),
            'recipients' => $this->reports->recipientsQuery($filters)->paginate(25, ['*'], 'recipients')->withQueryString(),
            'statuses' => RecipientStatus::cases(),
            'totals' => $totals,
            'rates' => [
                'delivery' => $pct($totals->delivered, $totals->sent),
                'open' => $pct($totals->opened, $base),
                'click' => $pct($totals->clicked, $base),
                'bounce' => $pct($totals->bounced, $totals->sent),
                'unsubscribe' => $pct($totals->unsubscribed, $base),
            ],
        ]);
    }

    public function export(ReportFilterRequest $request): BinaryFileResponse
    {
        $name = 'email-report-'.now()->format('Ymd-His').'.csv';

        return ExcelFacade::download(new RecipientsExport($this->reports->recipientsQuery($request->validated())), $name, Excel::CSV);
    }
}

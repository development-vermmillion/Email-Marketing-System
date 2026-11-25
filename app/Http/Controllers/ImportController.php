<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportCsvRequest;
use App\Models\AudienceGroup;
use App\Models\CsvImport;
use App\Services\CsvImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function __construct(private CsvImportService $imports) {}

    public function create(): View
    {
        return view('imports.create', [
            'groups' => AudienceGroup::orderBy('name')->get(),
            'recent' => CsvImport::latest('id')->limit(10)->get(),
            'maxMb' => round(config('mailing.csv.max_kb') / 1024),
        ]);
    }

    public function store(ImportCsvRequest $request): RedirectResponse
    {
        $import = $this->imports->start($request->file('file'), $request->input('group_ids', []), $request->user()->id);

        return redirect()->route('imports.show', $import)->with('success', 'Upload received. Contacts are being imported in the background.');
    }

    public function show(CsvImport $import): View
    {
        return view('imports.show', ['import' => $import]);
    }

    public function progress(CsvImport $import): JsonResponse
    {
        return response()->json([
            'status' => $import->status,
            'progress' => $import->progress,
            'total' => $import->total_rows,
            'processed' => $import->processed_rows,
            'imported' => $import->imported_count,
            'skipped' => $import->skipped_count,
            'failed' => $import->failed_count,
            'error' => $import->error,
            'finished' => $import->isFinished(),
        ]);
    }

    /** ?type=all (every skipped + failed row with its reason) or ?type=failed */
    public function download(Request $request, CsvImport $import): StreamedResponse
    {
        abort_unless($import->results_path && $this->imports->disk()->exists($import->results_path), 404);

        $onlyFailed = $request->query('type') === 'failed';
        $path = $this->imports->disk()->path($import->results_path);
        $name = 'import-'.$import->id.($onlyFailed ? '-failed' : '-results').'.csv';

        return response()->streamDownload(function () use ($path, $onlyFailed) {
            $in = fopen($path, 'rb');
            $out = fopen('php://output', 'wb');
            fputcsv($out, fgetcsv($in, 0, ',', '"', ''), ',', '"', ''); // header

            while (($row = fgetcsv($in, 0, ',', '"', '')) !== false) {
                if (! $onlyFailed || ($row[2] ?? '') === 'failed') {
                    fputcsv($out, $row, ',', '"', '');
                }
            }

            fclose($in);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function sample(): StreamedResponse
    {
        return response()->streamDownload(function () {
            echo "email,name,phone,company\n";
            echo "jane.doe@example.com,Jane Doe,+91 98765 43210,Acme Inc.\n";
            echo "john.smith@example.com,John Smith,,Globex\n";
        }, 'contacts-sample.csv', ['Content-Type' => 'text/csv']);
    }
}

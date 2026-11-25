<?php

namespace App\Jobs;

use App\Models\CsvImport;
use App\Services\CsvImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Reads the uploaded CSV once and fans it out into chunk jobs. Never holds the whole file in memory. */
class ProcessCsvImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 900;

    public function __construct(public int $importId)
    {
        $this->onQueue(config('mailing.queues.imports'));
    }

    public function handle(CsvImportService $service): void
    {
        $import = CsvImport::find($this->importId);

        if ($import && $import->status === 'pending') {
            $service->dispatchChunks($import);
        }
    }

    public function failed(Throwable $e): void
    {
        CsvImport::whereKey($this->importId)->update([
            'status' => 'failed',
            'error' => mb_substr($e->getMessage(), 0, 500),
            'finished_at' => now(),
        ]);
    }
}

<?php

namespace App\Jobs;

use App\Models\CsvImport;
use App\Services\CsvImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportCsvChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 300;

    /** @param list<array{0: int, 1: array<string, ?string>}> $rows */
    public function __construct(public int $importId, public array $rows)
    {
        $this->onQueue(config('mailing.queues.imports'));
    }

    public function handle(CsvImportService $service): void
    {
        $import = CsvImport::find($this->importId);

        if ($import && $import->status === 'processing') {
            $service->processChunk($import, $this->rows);
        }
    }

    public function failed(Throwable $e): void
    {
        CsvImport::whereKey($this->importId)->update([
            'status' => 'failed',
            'error' => 'A chunk failed: '.mb_substr($e->getMessage(), 0, 400),
            'finished_at' => now(),
        ]);
    }
}

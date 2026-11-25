<?php

namespace App\Console\Commands;

use App\Models\CsvImport;
use App\Models\EmailEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanupExpiredData extends Command
{
    protected $signature = 'data:cleanup';

    protected $description = 'Remove expired temporary data: uploaded CSVs, old import results, old tracking events, stale password resets';

    public function handle(): int
    {
        $disk = Storage::disk(config('mailing.csv.disk'));

        // The original upload is only needed while the import runs; keep result reports for 30 days.
        CsvImport::query()->where('stored_path', '!=', '')->where('created_at', '<', now()->subDays(7))->each(function (CsvImport $i) use ($disk) {
            $disk->delete($i->stored_path);
            $i->update(['stored_path' => '']);
        });

        $old = CsvImport::query()->where('created_at', '<', now()->subDays(30))->get();
        foreach ($old as $import) {
            $import->results_path && $disk->delete($import->results_path);
            $import->delete();
        }

        $events = 0;
        do {
            $deleted = EmailEvent::query()
                ->where('created_at', '<', now()->subDays((int) config('mailing.event_retention_days')))
                ->limit(5000)->delete();
            $events += $deleted;
        } while ($deleted > 0);

        DB::table('password_reset_tokens')->where('created_at', '<', now()->subDay())->delete();

        $this->info("Removed {$old->count()} import record(s) and {$events} old event(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Services;

use App\Jobs\ProcessCsvImportJob;
use App\Models\Audience;
use App\Models\CsvImport;
use App\Support\Email;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CSV contact import. The HTTP request only validates the header, stores the file and queues a job; rows are
 * processed in background chunks (config mailing.csv.chunk_size), so file size is bounded by the upload limit,
 * never by PHP's request time or memory.
 */
class CsvImportService
{
    /** Accepted header spellings -> canonical field. */
    private const ALIASES = [
        'email' => ['email', 'e-mail', 'email address', 'email_address', 'emailaddress', 'mail'],
        'name' => ['name', 'full name', 'full_name', 'fullname', 'contact name', 'contact_name'],
        'first_name' => ['first name', 'first_name', 'firstname', 'given name'],
        'last_name' => ['last name', 'last_name', 'lastname', 'surname', 'family name'],
        'phone' => ['phone', 'phone number', 'phone_number', 'mobile', 'mobile number', 'telephone', 'tel'],
        'company' => ['company', 'company name', 'company_name', 'organization', 'organisation', 'business'],
    ];

    public function __construct(
        private SuppressionService $suppression,
        private ActivityLogger $activity,
    ) {}

    public function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('mailing.csv.disk'));
    }

    /** @param array<int> $groupIds */
    public function start(UploadedFile $file, array $groupIds, ?int $userId): CsvImport
    {
        $directory = trim(config('mailing.csv.directory'), '/');
        $stored = $file->storeAs($directory, Str::uuid()->toString().'.csv', config('mailing.csv.disk'));
        $absolute = $this->disk()->path($stored);

        try {
            $map = $this->readHeader($absolute);
        } catch (ValidationException $e) {
            $this->disk()->delete($stored);
            throw $e;
        }

        $resultsPath = $directory.'/'.Str::uuid()->toString().'-results.csv';
        $this->disk()->put($resultsPath, "line,email,status,reason\n");

        $import = CsvImport::create([
            'user_id' => $userId,
            // Only the sanitised base name: the stored name is a random UUID, so the upload cannot influence paths.
            'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 200),
            'stored_path' => $stored,
            'results_path' => $resultsPath,
            'status' => 'pending',
            'group_ids' => array_values(array_unique(array_map('intval', $groupIds))),
            'header_map' => $map,
            'total_rows' => $this->countRows($absolute, $map['delimiter']),
        ]);

        $this->activity->log('import', 'started', "CSV import \"{$import->original_filename}\" queued ({$import->total_rows} rows)", null, ['csv_import_id' => $import->id]);

        ProcessCsvImportJob::dispatch($import->id);

        return $import;
    }

    /**
     * @return array{delimiter: string, columns: array<string, int>, ignored: list<string>}
     *
     * @throws ValidationException when the header has no email column
     */
    public function readHeader(string $absolutePath): array
    {
        $handle = fopen($absolutePath, 'rb');
        $line = $handle ? fgets($handle) : false;
        $handle && fclose($handle);

        if ($line === false || trim($line) === '') {
            throw ValidationException::withMessages(['file' => 'The CSV file is empty.']);
        }

        $line = $this->stripBom($line);
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($line, $d))->first();
        $headers = str_getcsv($line, $delimiter, '"', '');
        $columns = [];
        $ignored = [];

        foreach ($headers as $index => $header) {
            $key = mb_strtolower(trim($this->toUtf8((string) $header)));
            $field = collect(self::ALIASES)->search(fn (array $aliases) => in_array($key, $aliases, true));

            if ($field === false) {
                $ignored[] = $key;
            } elseif (! isset($columns[$field])) {
                $columns[$field] = $index;
            }
        }

        if (! isset($columns['email'])) {
            throw ValidationException::withMessages([
                'file' => 'The CSV header must contain an "email" column. Found: '.implode(', ', array_filter(array_map('trim', $headers))).'.',
            ]);
        }

        return ['delimiter' => $delimiter, 'columns' => $columns, 'ignored' => $ignored];
    }

    private function countRows(string $path, string $delimiter): int
    {
        $count = 0;
        $handle = fopen($path, 'rb');
        fgetcsv($handle, 0, $delimiter, '"', ''); // header

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($row !== [null]) { // skip blank lines
                $count++;
            }
        }

        fclose($handle);

        return $count;
    }

    /** Streams the file and queues one job per chunk. Called from ProcessCsvImportJob. */
    public function dispatchChunks(CsvImport $import): void
    {
        $import->update(['status' => 'processing', 'started_at' => now()]);

        $map = $import->header_map;
        $handle = fopen($this->disk()->path($import->stored_path), 'rb');
        fgetcsv($handle, 0, $map['delimiter'], '"', '');

        $size = max(50, (int) config('mailing.csv.chunk_size'));
        $line = 1;
        $chunk = [];

        while (($row = fgetcsv($handle, 0, $map['delimiter'], '"', '')) !== false) {
            $line++;

            if ($row === [null]) {
                continue;
            }

            $chunk[] = [$line, $this->extract($row, $map['columns'])];

            if (count($chunk) >= $size) {
                \App\Jobs\ImportCsvChunkJob::dispatch($import->id, $chunk);
                $chunk = [];
            }
        }

        if ($chunk) {
            \App\Jobs\ImportCsvChunkJob::dispatch($import->id, $chunk);
        }

        fclose($handle);

        if ($import->total_rows === 0) {
            $this->finish($import);
        }
    }

    /**
     * @param  list<array{0: int, 1: array<string, ?string>}>  $rows  [line number, field map]
     */
    public function processChunk(CsvImport $import, array $rows): void
    {
        $groupIds = $import->group_ids ?? [];
        $counts = ['imported' => 0, 'skipped' => 0, 'failed' => 0];
        $report = [];
        $seen = [];
        $touched = []; // audience ids to attach to groups

        $emails = collect($rows)->map(fn ($r) => Email::normalize($r[1]['email'] ?? ''))->filter(fn ($e) => Email::isValid($e))->unique()->values()->all();
        $existing = Audience::withTrashed()->whereIn('email', $emails)->get()->keyBy('email');
        $suppressed = $this->suppression->suppressedAmong($emails);

        foreach ($rows as [$line, $data]) {
            $email = Email::normalize($data['email'] ?? '');

            if (! Email::isValid($email)) {
                $counts['failed']++;
                $report[] = [$line, $data['email'] ?? '', 'failed', $email === '' ? 'Email is missing' : 'Invalid email address format'];

                continue;
            }

            if (isset($seen[$email])) {
                $counts['skipped']++;
                $report[] = [$line, $email, 'skipped', "Duplicate of line {$seen[$email]} in this file"];

                continue;
            }
            $seen[$email] = $line;

            if (isset($suppressed[$email])) {
                $counts['skipped']++;
                $report[] = [$line, $email, 'skipped', 'On the suppression list (unsubscribed, bounced or complained) - not imported'];

                continue;
            }

            $found = $existing->get($email);

            if ($found && ! $found->trashed()) {
                $touched[] = $found->id;
                $counts['skipped']++;
                $report[] = [$line, $email, 'skipped', 'Duplicate: contact already exists'.($groupIds ? ' (added to the selected groups)' : '')];

                continue;
            }

            try {
                $attributes = [
                    'name' => $this->limit($data['name'] ?? null, 255),
                    'phone' => $this->limit($data['phone'] ?? null, 50),
                    'company' => $this->limit($data['company'] ?? null, 255),
                    'source' => 'csv_import',
                    'csv_import_id' => $import->id,
                ];

                if ($found) { // previously deleted: bring it back
                    $found->restore();
                    $found->update($attributes);
                    $audience = $found;
                } else {
                    $audience = Audience::create($attributes + [
                        'email' => $email,
                        'status' => 'active',
                        'subscription_status' => 'subscribed',
                        'consent_at' => now(),
                    ]);
                }

                $touched[] = $audience->id;
                $counts['imported']++;
            } catch (UniqueConstraintViolationException) {
                // Another chunk inserted the same address a moment ago.
                $counts['skipped']++;
                $report[] = [$line, $email, 'skipped', 'Duplicate: contact already exists'];
            } catch (\Throwable $e) {
                $counts['failed']++;
                $report[] = [$line, $email, 'failed', 'Could not be saved: '.mb_substr($e->getMessage(), 0, 150)];
            }
        }

        if ($groupIds && $touched) {
            $now = now();
            $pivot = [];
            foreach (array_unique($touched) as $audienceId) {
                foreach ($groupIds as $groupId) {
                    $pivot[] = ['audience_id' => $audienceId, 'audience_group_id' => $groupId, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            foreach (array_chunk($pivot, 1000) as $part) {
                DB::table('audience_group_contacts')->insertOrIgnore($part);
            }
        }

        $this->appendReport($import, $report);

        CsvImport::whereKey($import->id)->update([
            'processed_rows' => DB::raw('processed_rows + '.count($rows)),
            'imported_count' => DB::raw('imported_count + '.$counts['imported']),
            'skipped_count' => DB::raw('skipped_count + '.$counts['skipped']),
            'failed_count' => DB::raw('failed_count + '.$counts['failed']),
        ]);

        // The chunk that brings processed_rows up to total_rows closes the import (atomic: only one wins).
        $closed = CsvImport::whereKey($import->id)
            ->where('status', 'processing')
            ->whereColumn('processed_rows', '>=', 'total_rows')
            ->update(['status' => 'completed', 'finished_at' => now()]);

        if ($closed) {
            $this->finish($import->refresh(), false);
        }
    }

    public function finish(CsvImport $import, bool $markCompleted = true): void
    {
        if ($markCompleted) {
            $import->update(['status' => 'completed', 'finished_at' => now()]);
        }

        $this->activity->log('import', 'completed', sprintf(
            'CSV import "%s" finished: %d imported, %d skipped, %d failed',
            $import->original_filename, $import->imported_count, $import->skipped_count, $import->failed_count
        ), null, ['csv_import_id' => $import->id]);
    }

    /** @param list<array<int, mixed>> $report */
    private function appendReport(CsvImport $import, array $report): void
    {
        if (! $report || ! $import->results_path) {
            return;
        }

        $buffer = fopen('php://temp', 'r+');
        foreach ($report as $row) {
            // Neutralise spreadsheet formula injection in downloaded reports.
            $row[1] = preg_match('/^[=+\-@\t\r]/', (string) $row[1]) ? "'".$row[1] : $row[1];
            fputcsv($buffer, $row, ',', '"', '');
        }
        rewind($buffer);

        file_put_contents($this->disk()->path($import->results_path), stream_get_contents($buffer), FILE_APPEND | LOCK_EX);
        fclose($buffer);
    }

    /**
     * @param  array<int, ?string>  $row
     * @param  array<string, int>  $columns
     * @return array<string, ?string>
     */
    private function extract(array $row, array $columns): array
    {
        $get = fn (string $field) => isset($columns[$field]) ? trim($this->toUtf8((string) ($row[$columns[$field]] ?? ''))) : null;

        $name = $get('name');
        if (($name === null || $name === '') && (isset($columns['first_name']) || isset($columns['last_name']))) {
            $name = trim(($get('first_name') ?? '').' '.($get('last_name') ?? ''));
        }

        return [
            'email' => $get('email'),
            'name' => $name,
            'phone' => $get('phone'),
            'company' => $get('company'),
        ];
    }

    private function limit(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function stripBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }

    private function toUtf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}

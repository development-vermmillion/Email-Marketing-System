<?php

namespace App\Http\Controllers;

use App\Jobs\ValidateEmailsJob;
use App\Models\Audience;
use App\Models\AudienceGroup;
use App\Models\EmailValidationRun;
use App\Services\ActivityLogger;
use App\Services\EmailValidator;
use App\Services\Verification\StreamSmtpConnection;
use App\Support\ValidationResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailValidationController extends Controller
{
    public function __construct(private EmailValidator $validator, private ActivityLogger $activity) {}

    public function index(): View
    {
        $counts = Audience::query()
            ->selectRaw('email_validation_status as s, count(*) as c')
            ->groupBy('email_validation_status')
            ->pluck('c', 's');

        return view('validation.index', [
            'counts' => [
                'valid' => (int) ($counts['valid'] ?? 0),
                'risky' => (int) ($counts['risky'] ?? 0),
                'invalid' => (int) ($counts['invalid'] ?? 0),
                'unchecked' => (int) ($counts[''] ?? 0),
            ],
            'groups' => AudienceGroup::orderBy('name')->get(),
            'runs' => EmailValidationRun::with('group')->latest('id')->limit(10)->get(),
            'activeRun' => EmailValidationRun::whereIn('status', ['pending', 'processing'])->latest('id')->first(),
            'probeEnabled' => (bool) config('mailing.validation.smtp_probe'),
            'probeAvailable' => EmailValidator::probeAvailable(),
            'dnsEnabled' => (bool) config('mailing.validation.dns', true),
            'skipInvalid' => (bool) config('mailing.validation.skip_invalid', true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['unchecked', 'all', 'group'])],
            'group_id' => ['nullable', 'required_if:scope,group', 'integer', 'exists:audience_groups,id'],
            'deactivate_invalid' => ['nullable', 'boolean'],
            'smtp_probe' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['smtp_probe']) && ! config('mailing.validation.smtp_probe')) {
            return back()->withInput()->with('error', 'Mailbox checks are switched off. Set MAILING_VALIDATION_SMTP_PROBE=true in .env first (see the README).');
        }

        if (EmailValidationRun::whereIn('status', ['pending', 'processing'])->exists()) {
            return back()->with('error', 'A validation run is already in progress. Wait for it to finish or cancel it first.');
        }

        $run = EmailValidationRun::create([
            'scope' => $data['scope'],
            'group_id' => $data['scope'] === 'group' ? $data['group_id'] : null,
            'deactivate_invalid' => (bool) ($data['deactivate_invalid'] ?? false),
            'smtp_probe' => (bool) ($data['smtp_probe'] ?? false),
            'user_id' => $request->user()->id,
        ]);

        $this->activity->log('audience', 'validation_started', 'Started email validation: '.$run->scopeLabel(), null, ['run_id' => $run->id]);
        ValidateEmailsJob::dispatch($run->id);

        return redirect()->route('validation.show', $run)->with('success', 'Validation started. It runs in the background, so you can leave this page.');
    }

    public function show(EmailValidationRun $run): View
    {
        return view('validation.show', ['run' => $run->load('group', 'user')]);
    }

    public function progress(EmailValidationRun $run): JsonResponse
    {
        return response()->json([
            'status' => $run->status,
            'progress' => $run->progress,
            'total' => $run->total,
            'processed' => $run->processed,
            'valid' => $run->valid_count,
            'risky' => $run->risky_count,
            'invalid' => $run->invalid_count,
            'error' => $run->error,
            'finished' => $run->isFinished(),
        ]);
    }

    public function cancel(EmailValidationRun $run): RedirectResponse
    {
        EmailValidationRun::whereKey($run->id)->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'cancelled', 'finished_at' => now()]);

        return back()->with('success', 'Validation cancelled. Contacts already checked keep their result.');
    }

    /** Checks one address typed into the page (nothing is saved). */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:255'], 'deep' => ['nullable', 'boolean']]);
        $deep = (bool) ($data['deep'] ?? false);

        $result = $this->validator->validate($data['email'], $deep)->toArray();
        $result['mailbox_checked'] = $deep && EmailValidator::probeAvailable();

        return response()->json($result);
    }

    /** Tells the admin whether this machine can reach other mail servers on port 25 (many networks block it). */
    public function probeTest(): JsonResponse
    {
        $port = (int) config('mailing.validation.probe_port', 25);
        $started = microtime(true);

        foreach (['gmail-smtp-in.l.google.com', 'mx1.mail.yahoo.com', 'outlook-com.olc.protection.outlook.com'] as $host) {
            try {
                $conn = new StreamSmtpConnection($host, $port, 6);
                [$code] = $conn->read();
                $conn->close();

                if ($code === 220) {
                    return response()->json(['ok' => true, 'message' => "Port {$port} is open: {$host} answered. Mailbox checks can run from this machine."]);
                }
            } catch (\Throwable) {
                // try the next one
            }
        }

        return response()->json(['ok' => false, 'message' => "Could not reach any mail server on port {$port} (tried for ".round(microtime(true) - $started)."s). Your network or hosting provider is probably blocking outbound port 25, so mailbox checks cannot work from here."]);
    }

    /** Marks every contact that failed validation as inactive so they drop out of future sends. */
    public function deactivate(): RedirectResponse
    {
        $count = Audience::where('email_validation_status', ValidationResult::INVALID)->where('status', 'active')->update(['status' => 'inactive']);
        $this->activity->log('audience', 'invalid_deactivated', "Deactivated {$count} contacts with invalid email addresses.");

        return back()->with('success', "{$count} contacts with invalid addresses were set to inactive. You can reactivate any of them from the Audiences page.");
    }

    public function export(Request $request): StreamedResponse
    {
        $status = $request->query('status', 'invalid');
        abort_unless(in_array($status, ['invalid', 'risky', 'valid'], true), 404);

        return response()->streamDownload(function () use ($status) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'name', 'company', 'validation', 'reason', 'checked_at']);

            Audience::where('email_validation_status', $status)->orderBy('id')->chunkById(1000, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    fputcsv($out, array_map([$this, 'safeCell'], [
                        $a->email, $a->name, $a->company, $a->email_validation_status,
                        ValidationResult::label($a->email_validation_reason), $a->email_validated_at?->toDateTimeString(),
                    ]));
                }
            });

            fclose($out);
        }, "emails-{$status}-".now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Stops spreadsheet apps from running a cell that starts with = + - @ as a formula. */
    private function safeCell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}

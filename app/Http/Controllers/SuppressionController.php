<?php

namespace App\Http\Controllers;

use App\Enums\SuppressionReason;
use App\Http\Requests\SuppressionRequest;
use App\Models\SuppressionEntry;
use App\Services\ActivityLogger;
use App\Services\SuppressionService;
use App\Support\Email;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SuppressionController extends Controller
{
    public function __construct(private SuppressionService $suppression, private ActivityLogger $activity) {}

    public function index(Request $request): View
    {
        $entries = SuppressionEntry::query()
            ->when($request->query('reason'), fn ($q, $r) => $q->where('reason', $r))
            ->when($request->query('q'), fn ($q, $term) => $q->where('email', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('suppression.index', [
            'entries' => $entries,
            'counts' => SuppressionEntry::selectRaw('reason, COUNT(*) AS c')->groupBy('reason')->pluck('c', 'reason'),
            'filters' => $request->only(['q', 'reason']),
        ]);
    }

    public function store(SuppressionRequest $request): RedirectResponse
    {
        $emails = collect(preg_split('/[\s,;]+/', (string) $request->validated('emails'), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($e) => Email::normalize($e))->unique();

        $valid = $emails->filter(fn ($e) => Email::isValid($e));

        foreach ($valid as $email) {
            $this->suppression->add($email, SuppressionReason::Manual, 'admin', null, $request->validated('note'));
        }

        $this->activity->log('suppression', 'added', "{$valid->count()} address(es) added to the suppression list");

        $invalid = $emails->count() - $valid->count();

        return back()->with('success', "{$valid->count()} address(es) suppressed.".($invalid ? " {$invalid} invalid entr".($invalid === 1 ? 'y was' : 'ies were').' ignored.' : ''));
    }

    public function destroy(SuppressionEntry $entry): RedirectResponse
    {
        try {
            $this->suppression->remove($entry);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->activity->log('suppression', 'removed', 'A manual suppression was removed');

        return back()->with('success', 'Removed from the suppression list.');
    }

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['email', 'reason', 'source', 'added_at'], ',', '"', '');

            SuppressionEntry::query()->orderBy('id')->chunkById(2000, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    fputcsv($out, [$r->email, $r->reason->value, $r->source, $r->created_at], ',', '"', '');
                }
            });

            fclose($out);
        }, 'suppression-list.csv', ['Content-Type' => 'text/csv']);
    }
}

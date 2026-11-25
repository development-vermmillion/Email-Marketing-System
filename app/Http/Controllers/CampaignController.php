<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Http\Requests\CampaignRequest;
use App\Http\Requests\ScheduleCampaignRequest;
use App\Http\Requests\TestEmailRequest;
use App\Models\AudienceGroup;
use App\Models\Campaign;
use App\Models\EmailTemplate;
use App\Models\SmtpSetting;
use App\Services\CampaignService;
use App\Services\RecipientPreparer;
use App\Services\TemplateRenderer;
use App\Services\TestEmailService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function __construct(private CampaignService $campaigns) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');
        $search = trim((string) $request->query('q'));

        $query = Campaign::query()->with('template:id,name');

        if ($status === 'trashed') {
            $query->onlyTrashed();
        } elseif ($status && in_array($status, CampaignStatus::values(), true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('subject', 'like', $like));
        }

        return view('campaigns.index', [
            'campaigns' => $query->latest('id')->paginate(15)->withQueryString(),
            'counts' => Campaign::query()->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status'),
            'trashedCount' => Campaign::onlyTrashed()->count(),
            'status' => $status,
            'search' => $search,
        ]);
    }

    public function create(): View
    {
        return view('campaigns.form', $this->formData(new Campaign([
            'from_name' => SmtpSetting::resolveDefault()?->from_name,
            'from_email' => SmtpSetting::resolveDefault()?->from_email,
            'reply_to_email' => SmtpSetting::resolveDefault()?->reply_to_email,
        ])));
    }

    public function store(CampaignRequest $request): RedirectResponse
    {
        $campaign = $this->campaigns->create($request->validated(), $request->user()->id);

        return $this->afterSave($campaign, $request);
    }

    public function show(Campaign $campaign): View
    {
        $campaign->load(['template', 'smtpSetting', 'activities' => fn ($q) => $q->latest('id')->limit(15)]);

        return view('campaigns.show', ['campaign' => $campaign, 'groups' => AudienceGroup::whereIn('id', $campaign->group_ids ?? [])->get()]);
    }

    public function edit(Campaign $campaign): View|RedirectResponse
    {
        if (! $campaign->canEdit()) {
            return redirect()->route('campaigns.show', $campaign)->with('error', 'Sending has started, so recipients and content can no longer be edited. Duplicate the campaign to change them.');
        }

        return view('campaigns.form', $this->formData($campaign));
    }

    public function update(CampaignRequest $request, Campaign $campaign): RedirectResponse
    {
        try {
            $this->campaigns->update($campaign, $request->validated());
        } catch (DomainException $e) {
            return redirect()->route('campaigns.show', $campaign)->with('error', $e->getMessage());
        }

        return $this->afterSave($campaign, $request);
    }

    /** "Save as draft" / "Schedule" / "Send now" buttons all post the same form. */
    private function afterSave(Campaign $campaign, CampaignRequest $request): RedirectResponse
    {
        $action = $request->input('action', 'draft');

        try {
            if ($action === 'send') {
                $this->campaigns->sendNow($campaign);

                return redirect()->route('campaigns.show', $campaign)->with('success', 'Campaign queued. Sending continues in the background even if you close the browser.');
            }

            if ($action === 'schedule') {
                if (! $request->filled('scheduled_at')) {
                    throw new DomainException('Pick a date and time to schedule the campaign.');
                }

                $this->campaigns->schedule($campaign, Carbon::parse($request->input('scheduled_at')));

                return redirect()->route('campaigns.show', $campaign)->with('success', 'Campaign scheduled for '.$campaign->refresh()->scheduled_at->format('M j, Y H:i').' ('.config('app.timezone').').');
            }
        } catch (DomainException $e) {
            return redirect()->route('campaigns.edit', $campaign)->with('error', 'Saved as draft, but: '.$e->getMessage());
        }

        return redirect()->route('campaigns.show', $campaign)->with('success', 'Draft saved.');
    }

    public function destroy(Campaign $campaign): RedirectResponse
    {
        try {
            $this->campaigns->trash($campaign);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('campaigns.index')->with('success', 'Campaign moved to trash. You can restore it for '.config('mailing.trash_retention_days').' days.');
    }

    public function restore(Campaign $campaign): RedirectResponse
    {
        $this->campaigns->restore($campaign);

        return redirect()->route('campaigns.show', $campaign)->with('success', 'Campaign restored.');
    }

    public function duplicate(Request $request, Campaign $campaign): RedirectResponse
    {
        $copy = $this->campaigns->duplicate($campaign, $request->user()->id);

        return redirect()->route('campaigns.edit', $copy)->with('success', 'Campaign duplicated as a draft.');
    }

    public function send(Campaign $campaign): RedirectResponse
    {
        return $this->attempt(fn () => $this->campaigns->sendNow($campaign), 'Campaign queued. Sending continues in the background.');
    }

    public function schedule(ScheduleCampaignRequest $request, Campaign $campaign): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->campaigns->schedule($campaign, Carbon::parse($request->input('scheduled_at'))),
            'Campaign scheduled.'
        );
    }

    public function unschedule(Campaign $campaign): RedirectResponse
    {
        return $this->attempt(fn () => $this->campaigns->unschedule($campaign), 'Schedule removed; the campaign is a draft again.');
    }

    public function pause(Campaign $campaign): RedirectResponse
    {
        return $this->attempt(fn () => $this->campaigns->pause($campaign), 'Campaign paused. Progress is kept.');
    }

    public function resume(Campaign $campaign): RedirectResponse
    {
        return $this->attempt(fn () => $this->campaigns->resume($campaign), 'Campaign resumed.');
    }

    public function cancel(Campaign $campaign): RedirectResponse
    {
        return $this->attempt(fn () => $this->campaigns->cancel($campaign), 'Campaign cancelled. Unsent recipients were skipped.');
    }

    public function test(TestEmailRequest $request, Campaign $campaign, TestEmailService $tester): JsonResponse
    {
        $campaign->load('template');
        $smtp = $campaign->smtp_setting_id ? SmtpSetting::find($campaign->smtp_setting_id) : null;

        $result = $tester->send(
            $smtp,
            $request->validated('to_email'),
            $campaign->subject,
            $campaign->html_snapshot ?: (string) $campaign->template?->html_content,
            $campaign->from_email,
            $campaign->from_name,
            $campaign->reply_to_email,
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function preview(Campaign $campaign, TemplateRenderer $renderer): Response
    {
        $html = $campaign->html_snapshot ?: (string) $campaign->template?->html_content;

        return response($renderer->renderPreview($html))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Security-Policy', "default-src 'none'; img-src * data:; style-src 'unsafe-inline' *; font-src *")
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }

    public function progress(Campaign $campaign): JsonResponse
    {
        return response()->json([
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'badge' => $campaign->status->badge(),
            'note' => $campaign->status_note,
            'progress' => $campaign->progress,
            'finished' => $campaign->status->isFinished() || $campaign->status === CampaignStatus::Trashed,
            'counts' => $campaign->only([
                'total_recipients', 'queued_count', 'sent_count', 'delivered_count', 'opened_count', 'clicked_count',
                'bounced_count', 'failed_count', 'complained_count', 'unsubscribed_count', 'skipped_count',
            ]),
        ]);
    }

    public function estimate(Request $request, RecipientPreparer $preparer): JsonResponse
    {
        $ids = array_map('intval', (array) $request->input('group_ids', []));

        return response()->json(['count' => $preparer->estimate($ids)]);
    }

    /** @return array<string, mixed> */
    private function formData(Campaign $campaign): array
    {
        return [
            'campaign' => $campaign,
            'templates' => EmailTemplate::orderBy('name')->get(['id', 'name', 'default_subject']),
            'groups' => AudienceGroup::withCount('audiences')->orderBy('name')->get(),
            'smtpSettings' => SmtpSetting::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'defaults' => config('mailing'),
            'timezone' => config('app.timezone'),
        ];
    }
}

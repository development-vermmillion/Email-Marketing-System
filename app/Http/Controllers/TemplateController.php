<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmailTemplateRequest;
use App\Http\Requests\TestEmailRequest;
use App\Models\EmailTemplate;
use App\Models\SmtpSetting;
use App\Services\ActivityLogger;
use App\Services\TemplateRenderer;
use App\Services\TestEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class TemplateController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): View
    {
        return view('templates.index', ['templates' => EmailTemplate::withCount('campaigns')->latest('id')->paginate(15)]);
    }

    public function create(): View
    {
        return view('templates.form', ['template' => new EmailTemplate(['html_content' => file_get_contents(database_path('seeders/stubs/welcome-template.html'))]), 'smtpSettings' => SmtpSetting::where('is_active', true)->get(['id', 'name'])]);
    }

    public function store(EmailTemplateRequest $request): RedirectResponse
    {
        $template = EmailTemplate::create($request->validated() + ['created_by' => $request->user()->id]);
        $this->activity->log('template', 'created', "Template \"{$template->name}\" created");

        return redirect()->route('templates.edit', $template)->with('success', 'Template saved.');
    }

    public function edit(EmailTemplate $template): View
    {
        return view('templates.form', ['template' => $template, 'smtpSettings' => SmtpSetting::where('is_active', true)->get(['id', 'name'])]);
    }

    public function update(EmailTemplateRequest $request, EmailTemplate $template): RedirectResponse
    {
        $template->update($request->validated());
        $this->activity->log('template', 'updated', "Template \"{$template->name}\" updated");

        return back()->with('success', 'Template saved. Campaigns that already started keep the version they were sent with.');
    }

    public function destroy(EmailTemplate $template): RedirectResponse
    {
        if ($template->campaigns()->withTrashed()->exists()) {
            return back()->with('error', 'This template is used by campaigns and cannot be deleted. Duplicate it instead, or delete those campaigns first.');
        }

        $template->delete();
        $this->activity->log('template', 'deleted', "Template \"{$template->name}\" deleted");

        return redirect()->route('templates.index')->with('success', 'Template deleted.');
    }

    public function duplicate(Request $request, EmailTemplate $template): RedirectResponse
    {
        $copy = EmailTemplate::create([
            'name' => 'Copy of '.$template->name,
            'default_subject' => $template->default_subject,
            'html_content' => $template->html_content,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('templates.edit', $copy)->with('success', 'Template duplicated.');
    }

    /** Live preview: rendered server-side so it uses the exact same escaping as real sends. */
    public function preview(Request $request, TemplateRenderer $renderer): Response
    {
        $request->validate(['html_content' => ['nullable', 'string', 'max:500000']]);

        return response($renderer->renderPreview((string) $request->input('html_content')))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Security-Policy', "default-src 'none'; img-src * data:; style-src 'unsafe-inline' *; font-src *")
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test(TestEmailRequest $request, EmailTemplate $template, TestEmailService $tester): JsonResponse
    {
        $smtp = $request->validated('smtp_setting_id') ? SmtpSetting::find($request->validated('smtp_setting_id')) : null;

        $result = $tester->send(
            $smtp,
            $request->validated('to_email'),
            (string) ($request->validated('subject') ?: $template->default_subject ?: $template->name),
            (string) ($request->validated('html_content') ?: $template->html_content),
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}

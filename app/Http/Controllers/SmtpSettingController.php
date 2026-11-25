<?php

namespace App\Http\Controllers;

use App\Http\Requests\SmtpSettingRequest;
use App\Http\Requests\TestEmailRequest;
use App\Models\SmtpSetting;
use App\Services\ActivityLogger;
use App\Services\Smtp\SmtpTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SmtpSettingController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): View
    {
        return view('smtp.index', [
            // Credentials are $hidden on the model and never serialised into the page; the view only knows has_password.
            'settings' => SmtpSetting::orderByDesc('is_default')->orderBy('name')->get(),
            'providers' => SmtpSetting::PROVIDERS,
            'defaults' => config('mailing'),
        ]);
    }

    public function store(SmtpSettingRequest $request): RedirectResponse
    {
        $setting = SmtpSetting::create($this->payload($request));
        $this->enforceSingleDefault($setting);
        $this->activity->log('smtp', 'created', "SMTP configuration \"{$setting->name}\" created");

        return back()->with('success', 'SMTP configuration saved. Credentials are encrypted at rest.');
    }

    public function update(SmtpSettingRequest $request, SmtpSetting $smtp): RedirectResponse
    {
        $data = $this->payload($request);

        // Blank credential fields on edit mean "keep what is stored" (they are never sent to the browser).
        foreach (['username', 'password', 'webhook_secret'] as $secret) {
            if (($data[$secret] ?? '') === '') {
                unset($data[$secret]);
            }
        }

        $smtp->update($data);
        $this->enforceSingleDefault($smtp);
        $this->activity->log('smtp', 'updated', "SMTP configuration \"{$smtp->name}\" updated");

        return back()->with('success', 'SMTP configuration updated.');
    }

    public function toggle(SmtpSetting $smtp): RedirectResponse
    {
        $smtp->update(['is_active' => ! $smtp->is_active]);
        $this->activity->log('smtp', $smtp->is_active ? 'enabled' : 'disabled', "SMTP configuration \"{$smtp->name}\" ".($smtp->is_active ? 'enabled' : 'disabled'));

        return back()->with('success', $smtp->is_active ? 'SMTP configuration enabled.' : 'SMTP configuration disabled. Running campaigns using it will pause.');
    }

    public function destroy(SmtpSetting $smtp): RedirectResponse
    {
        $name = $smtp->name;
        $smtp->delete();
        $this->activity->log('smtp', 'deleted', "SMTP configuration \"{$name}\" deleted");

        return back()->with('success', 'SMTP configuration deleted.');
    }

    public function testConnection(SmtpSetting $smtp, SmtpTester $tester): JsonResponse
    {
        $result = $tester->testConnection($smtp);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function testEmail(TestEmailRequest $request, SmtpSetting $smtp, SmtpTester $tester): JsonResponse
    {
        $result = $tester->sendTestEmail($smtp, $request->validated('to_email'));

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /** @return array<string, mixed> */
    private function payload(SmtpSettingRequest $request): array
    {
        return $request->safe()->except(['is_active', 'is_default']) + [
            'is_active' => $request->boolean('is_active'),
            'is_default' => $request->boolean('is_default'),
        ];
    }

    private function enforceSingleDefault(SmtpSetting $setting): void
    {
        if ($setting->is_default) {
            SmtpSetting::where('id', '!=', $setting->id)->update(['is_default' => false]);
        }
    }
}

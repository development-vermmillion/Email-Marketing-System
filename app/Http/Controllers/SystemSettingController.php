<?php

namespace App\Http\Controllers;

use App\Http\Requests\SystemSettingRequest;
use App\Models\Setting;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\View\View;
use Throwable;

class SystemSettingController extends Controller
{
    public function edit(): View
    {
        return view('settings.edit', [
            'settings' => [
                'company_name' => Setting::get('company_name', config('app.name')),
                'sender_address' => Setting::get('sender_address', ''),
                'track_opens' => Setting::get('track_opens', '1') === '1',
                'track_clicks' => Setting::get('track_clicks', '1') === '1',
                'public_unsubscribe_notice' => Setting::get('public_unsubscribe_notice', ''),
            ],
            'system' => $this->systemInfo(),
            'defaults' => config('mailing'),
        ]);
    }

    public function update(SystemSettingRequest $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validated();

        Setting::put('company_name', $data['company_name']);
        Setting::put('sender_address', $data['sender_address'] ?? '');
        Setting::put('track_opens', $request->boolean('track_opens') ? '1' : '0');
        Setting::put('track_clicks', $request->boolean('track_clicks') ? '1' : '0');
        Setting::put('public_unsubscribe_notice', $data['public_unsubscribe_notice'] ?? '');

        $activity->log('system', 'settings.updated', 'System settings updated');

        return back()->with('success', 'Settings saved.');
    }

    /** @return array<string, mixed> */
    private function systemInfo(): array
    {
        $info = ['queue_connection' => config('queue.default'), 'emails_waiting' => null, 'imports_waiting' => null, 'failed_jobs' => null];

        try {
            $info['emails_waiting'] = Queue::size(config('mailing.queues.emails'));
            $info['imports_waiting'] = Queue::size(config('mailing.queues.imports'));
        } catch (Throwable) {
            // Queue backend unreachable: leave null, the view shows "unavailable".
        }

        try {
            $info['failed_jobs'] = DB::table('failed_jobs')->count();
        } catch (Throwable) {
        }

        return $info;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\CampaignRecipient;
use App\Models\Setting;
use App\Services\UnsubscribeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public unsubscribe flow. The 64-character random token is the only credential.
 *  GET   -> confirmation page (a GET never unsubscribes, so link scanners / prefetchers cannot do it by accident)
 *  POST  -> unsubscribes. This is also the RFC 8058 one-click endpoint targeted by the List-Unsubscribe header.
 */
class UnsubscribeController extends Controller
{
    public function __construct(private UnsubscribeService $unsubscribes) {}

    public function show(string $token): View
    {
        $recipient = $this->find($token);

        return view('unsubscribe.show', $this->viewData($recipient));
    }

    public function store(Request $request, string $token): View|Response
    {
        $recipient = $this->find($token);

        $this->unsubscribes->unsubscribe($recipient, $request->has('List-Unsubscribe') ? 'one_click' : 'unsubscribe_link', $request->ip());

        return view('unsubscribe.show', $this->viewData($recipient) + ['justChanged' => 'unsubscribed']);
    }

    public function resubscribe(Request $request, string $token): View
    {
        $recipient = $this->find($token);
        $ok = $this->unsubscribes->resubscribe($recipient, $request->ip());

        return view('unsubscribe.show', $this->viewData($recipient) + ['justChanged' => $ok ? 'resubscribed' : 'blocked']);
    }

    private function find(string $token): CampaignRecipient
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, 404);

        return CampaignRecipient::where('tracking_token', $token)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function viewData(CampaignRecipient $recipient): array
    {
        [$local, $domain] = array_pad(explode('@', $recipient->email, 2), 2, '');

        return [
            'token' => $recipient->tracking_token,
            // Mask the address: the page is reachable by anyone holding the link.
            'maskedEmail' => mb_substr($local, 0, 1).str_repeat('•', max(2, mb_strlen($local) - 1)).'@'.$domain,
            'unsubscribed' => $this->unsubscribes->isUnsubscribed($recipient),
            'company' => Setting::get('company_name', config('app.name')),
            'notice' => Setting::get('public_unsubscribe_notice', 'You have been unsubscribed and will no longer receive marketing email from us.'),
            'justChanged' => null,
        ];
    }
}

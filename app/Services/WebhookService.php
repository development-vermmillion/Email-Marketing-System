<?php

namespace App\Services;

use App\Models\SmtpSetting;
use App\Services\Webhooks\GenericAdapter;
use App\Services\Webhooks\MailgunAdapter;
use App\Services\Webhooks\PostmarkAdapter;
use App\Services\Webhooks\SendGridAdapter;
use App\Services\Webhooks\SesAdapter;
use App\Services\Webhooks\WebhookAdapter;
use Illuminate\Http\Request;

class WebhookService
{
    /** @var array<string, WebhookAdapter> */
    private array $adapters;

    public function __construct()
    {
        foreach ([new GenericAdapter, new SendGridAdapter, new MailgunAdapter, new PostmarkAdapter, new SesAdapter] as $adapter) {
            $this->adapters[$adapter->provider()] = $adapter;
        }
    }

    public function adapter(string $provider): ?WebhookAdapter
    {
        return $this->adapters[$provider] ?? null;
    }

    /**
     * A request is trusted only if it verifies against the secret of at least one SMTP setting configured for
     * this provider. Settings are not required to be "active": late events for older campaigns still arrive.
     */
    public function authenticate(WebhookAdapter $adapter, Request $request): bool
    {
        $settings = SmtpSetting::query()
            ->where('provider', $adapter->provider())
            ->whereNotNull('webhook_secret')
            ->get();

        foreach ($settings as $setting) {
            if ($adapter->verify($request, (string) $setting->webhook_secret)) {
                return true;
            }
        }

        return false;
    }
}

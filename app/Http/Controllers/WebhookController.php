<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookEventsJob;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private WebhookService $webhooks) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $adapter = $this->webhooks->adapter($provider);

        abort_if($adapter === null, 404);

        // 1. Authenticate (signature / shared secret). Nothing is parsed or stored before this passes.
        if (! $this->webhooks->authenticate($adapter, $request)) {
            Log::warning('Webhook rejected: signature verification failed', ['provider' => $provider, 'ip' => $request->ip()]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // 2. Provider handshakes (e.g. SNS subscription confirmation).
        if ($note = $adapter->control($request)) {
            return response()->json(['status' => $note]);
        }

        // 3. Normalise and hand off to the queue so the provider gets a fast 2xx. Replays are harmless:
        //    events are de-duplicated by a unique key.
        $events = $adapter->parse($request);

        foreach (array_chunk($events, 200) as $chunk) {
            ProcessWebhookEventsJob::dispatch($chunk);
        }

        return response()->json(['status' => 'accepted', 'events' => count($events)], 202);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\TemplateRenderer;
use App\Services\TrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class TrackingController extends Controller
{
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(private TrackingService $tracking) {}

    /** Open pixel. It must always return the image, even if recording fails. */
    public function open(Request $request, string $token): Response
    {
        try {
            if ($recipient = $this->tracking->findByToken($token)) {
                $this->tracking->recordOpen($recipient, $request->ip(), $request->userAgent());
            }
        } catch (Throwable $e) {
            Log::warning('Open tracking failed', ['error' => $e->getMessage()]);
        }

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Click redirect. The destination is carried in the URL and authenticated with an HMAC, so this endpoint
     * cannot be abused as an open redirector: only URLs that were present in a sent email are accepted.
     */
    public function click(Request $request, string $token): RedirectResponse
    {
        $encoded = (string) $request->query('u', '');
        $signature = (string) $request->query('s', '');
        $url = TemplateRenderer::base64UrlDecode($encoded);

        abort_if($url === null || ! preg_match('#^https?://#i', $url), 404);
        abort_unless(hash_equals(TemplateRenderer::signature($token, $url), $signature), 404);

        try {
            if ($recipient = $this->tracking->findByToken($token)) {
                $this->tracking->recordClick($recipient, $url, $request->ip(), $request->userAgent());
            }
        } catch (Throwable $e) {
            Log::warning('Click tracking failed', ['error' => $e->getMessage()]);
        }

        return redirect()->away($url, 302)->header('Referrer-Policy', 'no-referrer');
    }
}

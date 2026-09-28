<?php

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Hardening headers on every response: no framing (clickjacking), no MIME sniffing, a strict
 * referrer and permissions policy, a content security policy, HSTS over HTTPS, and no caching of
 * pages that belong to a signed-in session.
 *
 * The CSP still allows inline scripts and styles and cdn.tailwindcss.com: the pages load Tailwind's
 * browser build and use inline styles. Building Tailwind into assets/ would let both be dropped.
 */
final class SecurityHeaders
{
    private const CSP = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com",
        "img-src 'self' data:",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ];

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        // The web profiler (dev only) needs its own scripts and styles.
        if (str_starts_with($request->getPathInfo(), '/_profiler') || str_starts_with($request->getPathInfo(), '/_wdt')) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', implode('; ', self::CSP));
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Anything shown in a session (every page but static assets) must not be kept by browsers or proxies.
        if ($request->hasPreviousSession() || $request->hasSession() && $request->getSession()->isStarted()) {
            $headers->set('Cache-Control', 'no-store, private');
            $headers->set('Pragma', 'no-cache');
        }
    }
}

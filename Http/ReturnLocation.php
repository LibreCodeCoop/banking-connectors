<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Http;

use Illuminate\Http\Request;

/**
 * A previous-list reference is display navigation, never an arbitrary URL.
 * Only a same-company Akaunting transactions index URI may be returned.
 */
final class ReturnLocation
{
    public static function validate(mixed $uri, string $expectedPath): ?string
    {
        if (!is_string($uri) || $uri === '' || strlen($uri) > 2048 ||
            !str_starts_with($uri, '/') || str_starts_with($uri, '//') ||
            (preg_match('/[\x00-\x1F\x7F]/', $uri) || str_contains($uri, chr(92)))) {
            return null;
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['port']) || isset($parts['fragment']) ||
            ($parts['path'] ?? null) !== $expectedPath) {
            return null;
        }
        return $uri;
    }

    public static function forCompany(mixed $uri, int $company): ?string
    {
        if ($company < 1) return null;
        $path = parse_url(route('transactions.index', ['company_id' => $company]), PHP_URL_PATH);
        return is_string($path) ? self::validate($uri, $path) : null;
    }

    public static function current(int $company): ?string
    {
        return self::forCompany(request()->getRequestUri(), $company);
    }

    public static function previous(Request $request, int $company): ?string
    {
        // Always prefer the explicit source carried by the list link.
        // The Referer fallback only handles older bookmarks and is validated.
        $source = self::forCompany($request->query('from'), $company);
        if ($source !== null) return $source;
        $referer = $request->headers->get('referer');
        if (!is_string($referer)) return null;
        $parts = parse_url($referer);
        $base = parse_url($request->getSchemeAndHttpHost());
        if (!is_array($parts) || !is_array($base) ||
            ($parts['scheme'] ?? null) !== ($base['scheme'] ?? null) ||
            ($parts['host'] ?? null) !== ($base['host'] ?? null) ||
            ($parts['port'] ?? null) !== ($base['port'] ?? null)) return null;
        return self::forCompany(($parts['path'] ?? '').(
            isset($parts['query']) ? '?'.$parts['query'] : ''
        ), $company);
    }
}

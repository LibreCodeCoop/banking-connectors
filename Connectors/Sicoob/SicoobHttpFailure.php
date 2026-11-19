<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Connectors\Sicoob;

use RuntimeException;
use Modules\BankingConnectors\Contracts\BankTransportFailure;

/**
 * Non-sensitive transport error. Never retains HTTP body, bearer tokens,
 * certificate paths, response headers or underlying curl error messages.
 */
final class SicoobHttpFailure extends RuntimeException implements BankTransportFailure
{
    public readonly string $safeCode;
    public readonly ?int $retryAfterSeconds;

    public function __construct(
        public readonly int $status,
        ?string $retryAfter = null,
        public readonly bool $statementRequest = false,
    ) {
        $this->safeCode = match (true) {
            $status === 0 => 'bank_api_network_failed',
            $status === 401 => 'bank_api_unauthorized',
            $status === 403 => 'bank_api_forbidden',
            $status === 429 => 'bank_api_rate_limited',
            in_array($status, [408, 425, 500, 502, 503, 504], true)
                => 'bank_api_temporarily_unavailable',
            default => 'bank_api_request_failed',
        };
        $this->retryAfterSeconds = self::parseRetryAfter($retryAfter);
        parent::__construct($this->safeCode);
    }

    public function retryDelaySeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public static function parseRetryAfter(?string $header): ?int
    {
        if (!is_string($header) || strlen($header) > 128) {
            return null;
        }
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        if (preg_match('/^[0-9]{1,7}$/D', $header)) {
            return min(3600, max(1, (int) $header));
        }
        // RFC 9110 permits an HTTP-date. Accept only a strict GMT form,
        // never free-form PHP parsing of an untrusted response header.
        $date = \DateTimeImmutable::createFromFormat(
            '!D, d M Y H:i:s \G\M\T', $header,
            new \DateTimeZone('GMT')
        );
        if ($date === false || $date->format('D, d M Y H:i:s \G\M\T') !== $header) {
            return null;
        }
        $seconds = $date->getTimestamp() - time();
        return $seconds > 0 ? min(3600, $seconds) : null;
    }
}

<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace Modules\BankingConnectors\Connectors\Sicoob;

final class SicoobHttpClient {
    /** @var array<string,array{token:string,until:int}> */
    private array $tokens = [];

    public function __construct(private array $config, private readonly ?\Closure $testExchange = null) {
        if (!in_array($config['MODE'] ?? '', ['sandbox', 'production'], true)) {
            throw new \InvalidArgumentException('MODE must be sandbox or production');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,256}$/D', (string) ($config['CLIENT_ID'] ?? ''))) {
            throw new \InvalidArgumentException('invalid_sicoob_client_id');
        }
    }

    private function request(string $method, string $url, array $headers, ?array $form = null): array {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            throw new \RuntimeException('HTTPS is required');
        }
        $allowed = $this->config['MODE'] === 'sandbox'
            ? ['sandbox.sicoob.com.br']
            : ['auth.sicoob.com.br', 'api.sicoob.com.br'];
        if (!in_array(strtolower((string) ($parts['host'] ?? '')), $allowed, true)) {
            throw new \RuntimeException('Unapproved Sicoob API host');
        }
        $retryAfterHeader = null;
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfterHeader): int {
                if (strncasecmp($header, 'Retry-After:', 12) === 0) {
                    $retryAfterHeader = trim(substr($header, 12));
                }
                return strlen($header);
            },
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($form !== null) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($form);
        }
        if (($this->config['MODE'] ?? '') === 'production') {
            foreach (['TLS_CERT_PATH' => CURLOPT_SSLCERT, 'TLS_KEY_PATH' => CURLOPT_SSLKEY] as $key => $opt) {
                $path = $this->config[$key] ?? '';
                if (!is_file($path) || !is_readable($path)) {
                    throw new \RuntimeException('Missing readable mTLS file: ' . $key);
                }
                $options[$opt] = $path;
            }
            $passwordFile = $this->config['TLS_KEY_PASSWORD_FILE'] ?? '';
            if (!is_file($passwordFile) || !is_readable($passwordFile)) {
                throw new \RuntimeException('Missing readable TLS_KEY_PASSWORD_FILE');
            }
            $options[CURLOPT_KEYPASSWD] = rtrim((string) file_get_contents($passwordFile), "\r\n");
        }
        if ($this->testExchange !== null) {
            // Internal test seam: exercises the same status/JSON logic without
            // real bank calls or an alternate production transport path.
            $fake = ($this->testExchange)($method, $url, $headers, $form);
            $response = $fake['body'] ?? null;
            $status = $fake['status'] ?? 0;
            $retryAfterHeader = $fake['retry_after'] ?? null;
            curl_close($ch);
        } else {
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }
        if (!is_string($response)) {
            throw new SicoobHttpFailure(0);
        }
        if ($status === 204 && $method === 'GET' && ($parts['host'] ?? '') !== 'auth.sicoob.com.br') {
            return ['resultado' => ['transacoes' => []]];
        }
        if ($status < 200 || $status >= 300) {
            throw new SicoobHttpFailure((int) $status, is_string($retryAfterHeader) ? $retryAfterHeader : null, $method === 'GET');
        }
        try {
            $json = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('bank_api_response_invalid');
        }
        if (!is_array($json)) {
            throw new \RuntimeException('Unexpected API response');
        }
        return $json;
    }

    private function accessToken(string $scope = 'cco_consulta'): string {
        if (($this->config['MODE'] ?? '') === 'sandbox') {
            $file = $this->config['SANDBOX_TOKEN_FILE'] ?? '';
            if (!is_file($file) || !is_readable($file)) {
                throw new \RuntimeException('Sandbox token file missing. See README.');
            }
            $token = trim((string) file_get_contents($file));
            if ($token === '') {
                throw new \RuntimeException('Sandbox token empty');
            }
            return preg_replace('/^Bearer\s+/i', '', $token) ?? $token;
        }
        if (isset($this->tokens[$scope]) && $this->tokens[$scope]['until'] > time()) {
            return $this->tokens[$scope]['token'];
        }
        $json = $this->request('POST', $this->config['AUTH_URL'], ['Content-Type: application/x-www-form-urlencoded'], [
            'grant_type' => 'client_credentials',
            'client_id' => $this->config['CLIENT_ID'],
            'scope' => $scope,
        ]);
        if (!isset($json['access_token']) || !is_string($json['access_token'])) {
            throw new \RuntimeException('Token response missing access_token');
        }
        $expiresIn = filter_var($json['expires_in'] ?? null, FILTER_VALIDATE_INT);
        $ttl = ($expiresIn !== false && $expiresIn !== null && $expiresIn > 0) ? min($expiresIn, 3600) : 60;
        $this->tokens[$scope] = ['token'=>$json['access_token'], 'until'=>time() + max(0, $ttl - 30)];
        return $this->tokens[$scope]['token'];
    }

    /** Read a single received Pix. Requires a separately authorized pix.read scope. */
    public function receivedPix(string $e2eid): array
    {
        if (!preg_match('/^E[0-9]{8}[A-Za-z0-9]{23}$/D',$e2eid)) {
            throw new \InvalidArgumentException('pix_end_to_end_id_invalid');
        }
        $base=$this->config['PIX_API_BASE_URL']??null;
        if (!is_string($base) || $base==='') {
            throw new \DomainException('pix_enrichment_not_configured');
        }
        $url=rtrim($base,'/').'/pix/'.$e2eid;
        $parts=parse_url($url);
        if (($parts['scheme']??null)!=='https' ||
            ($parts['host']??null)!==($this->config['MODE']==='production'
                ?'api.sicoob.com.br':'sandbox.sicoob.com.br') ||
            !str_starts_with((string)($parts['path']??''),'/pix/api/v2/pix/')) {
            throw new \DomainException('pix_api_endpoint_not_trusted');
        }
        $response=$this->request('GET',$url,[
            'Authorization: Bearer '.$this->accessToken('pix.read'),
            'client_id: '.$this->config['CLIENT_ID'],
            'Accept: application/json',
        ]);
        if (!isset($response['endToEndId']) || !isset($response['valor']) ||
            !isset($response['horario'])) {
            throw new \DomainException('pix_response_invalid');
        }
        return $response;
    }

    public function statement(int $year, int $month, int $dayFrom, int $dayTo): array {
        if ($month < 1 || $month > 12 || $year < 2020 || !checkdate($month, $dayFrom, $year) || !checkdate($month, $dayTo, $year) || $dayFrom > $dayTo) {
            throw new \InvalidArgumentException('Invalid calendar date range; a single month is required');
        }
        $account = $this->config['ACCOUNT_NUMBER'] ?? '';
        if (!preg_match('/^[0-9]+$/', $account)) {
            throw new \RuntimeException('ACCOUNT_NUMBER must contain only digits');
        }
        $url = rtrim($this->config['API_BASE_URL'], '/') . '/extrato/' . $month . '/' . $year . '?' . http_build_query([
            'diaInicial' => $dayFrom, 'diaFinal' => $dayTo, 'numeroContaCorrente' => $account,
        ]);
        try {
            return $this->request('GET', $url, [
                'Authorization: Bearer ' . $this->accessToken(),
                'client_id: ' . $this->config['CLIENT_ID'],
                'Accept: application/json',
            ]);
        } catch (\RuntimeException $e) {
            // A bank-statement 401 may mean the token was invalidated before its expiry.
            // Retry GET once with a fresh token; never retry a 403 or other error.
            if ($this->config['MODE'] !== 'production' || !($e instanceof SicoobHttpFailure) || ($e->status !== 401 || !$e->statementRequest)) {
                throw $e;
            }
            unset($this->tokens['cco_consulta']);
            return $this->request('GET', $url, [
                'Authorization: Bearer ' . $this->accessToken(),
                'client_id: ' . $this->config['CLIENT_ID'],
                'Accept: application/json',
            ]);
        }
    }
}

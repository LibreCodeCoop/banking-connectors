# Banking Connectors health and monitoring

## Authenticated, provider-neutral health endpoint

GET /api/v1/banking-connectors/health?company_id=2

Authenticate using the existing Akaunting API with a dedicated, least-privileged
monitoring account. The account requires the native read-banking-accounts and
read-banking-transactions permissions for the selected company. Anonymous
requests return 401, unprivileged requests 403, and cross-company requests are
denied. Responses include Cache-Control: private, no-store.

The returned JSON is versioned (schema_version=1) and contains:
- service, company_id, checked_at, state, probe_type;
- counts for enabled connections, pending and overdue requests, retries,
  failed connections, expiring or expired certificates;
- sanitized alert codes and numerical IDs only.

It never exposes access tokens, AppRole Secret IDs, certificates, PFX paths,
account numbers, transaction amounts or decrypted OpenBao values.

This endpoint is read-only and never makes network requests to banks or
OpenBao, so Zabbix, Prometheus-style HTTP collectors or any other monitoring
system may poll it repeatedly without consuming bank rate limits. HTTP 200
means the API can report status, not that every bank connection is healthy:
inspect the "state" field for "ok", "idle" (no enabled connections or
queued requests), or "attention", and the data.issues array for incident
classification. "idle" is neither an error nor proof of functioning bank
sync; monitoring policy can separately require enabled_connections > 0.

Example operational checks:
- state=attention or repeated_failures >= 3: operator investigation;
- overdue_requests > 0: stalled or retrying synchronization;
- expiring_certificates > 0: rotate bank mTLS certificate ahead of time;
- expired_certificates > 0: immediate intervention;
- unverified_credentials > 0: the last credential probe is older than 48h;
- stale_connections > 0: automatic synchronization did not complete in time.

Paused connections are deliberately not treated as failed bank services.
Providers without certificate-based authentication do not receive
Sicoob-specific certificate-expiry warnings.

## Active tests remain separate

The statement worker performs authenticated Sicoob GET requests on its own
schedule, not during monitoring scrapes. A separate local certificate probe
validates OpenBao Agent-rendered files without any bank network request:

    php artisan banking-connectors:sicoob-credential-probe --connection=1

Opt-in native daily scheduling is controlled by
BANKING_CONNECTORS_AUTO_CREDENTIAL_PROBE=yes. Its default is OFF. Normal statement
polling is a distinct opt-in setting, BANKING_CONNECTORS_AUTO_POLL_ENABLED=yes, also
OFF by default. With that flag enabled, the application's existing scheduler
processes pending manual requests every minute and eligible automatic bank
connections every 15 minutes. The Web controller also submits a
company-bound asynchronous job when Akaunting uses a real queue driver.
The system's `schedule:run` must actually be invoked once per minute;
a Redis queue worker alone does not replace scheduler execution.
Both cadences are disabled if the opt-in flag is absent.

The probe writes ONLY Banking Connectors-owned nonsecret metadata:
certificate_valid_to, credentials_checked_at and sanitized error code.
Bank statements, invoices and payment records remain untouched.

The existing CLI health check can be used on hosts with safe console access:

    php artisan banking-connectors:health --strict

It has no Zabbix dependency. Any external monitor may consume the
authenticated API using its own credential store, without installing an
agent or monitoring plugin inside Akaunting.

## Scope of evidence

Localhost HTTP authentication, role denial, tenant isolation, fixed schemas,
cached status and synthetic certificate expiration have been tested.
Sicoob API error handling (401/403/429/5xx, timeouts, Retry-After) was
tested against synthetic HTTP responses; a real read-only GET also passed.
Production Zabbix integration and actual OpenBao Agent runtime in production
are separate deployment verifications.

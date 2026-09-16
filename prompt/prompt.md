Add external reachability and domain-health monitoring to JetGrid, with
Telegram alerting.

WHY THIS EXISTS (read before designing)
A production domain was suspended by the registrar for unverified registrant
details. The nameservers were silently replaced with
NS1.VERIFICATION-HOLD.SUSPENDED-DOMAIN.COM. The server stayed healthy and
returned HTTP 200 to itself the entire time, so every existing check passed
while the site was unreachable worldwide. Localhost health is not health.

────────────────────────────────
1. NEW CHECKS (all read-only)
────────────────────────────────
Per monitored site, in addition to existing checks:

a) DNS resolution
   - Resolve the domain against at least two public resolvers (1.1.1.1,
     8.8.8.8). Record returned A/AAAA records.
   - ALERT if: NXDOMAIN, REFUSED, SERVFAIL, timeout, or empty answer.
   - ALERT if: resolved IP does not match the server's own public IP.
   - ALERT if: resolution returns a loopback (127.0.0.0/8) or private
     RFC1918 address — this indicates sinkholing or a misconfigured record.

b) Nameserver integrity
   - Record the NS records for each domain on first successful check as the
     expected baseline (store it; do not hardcode).
   - ALERT if NS records change at all.
   - ALERT CRITICAL if any NS hostname matches suspension patterns:
     suspended-domain, verification-hold, clienthold, serverhold,
     pendingdelete, or similar registrar hold indicators.

c) Registrar / WHOIS status
   - Poll WHOIS no more than once per 24h per domain (rate limits are real;
     cache aggressively and back off on failure).
   - Record: registry expiry date, domain status codes, nameservers.
   - ALERT if any EPP status contains: clientHold, serverHold,
     pendingDelete, redemptionPeriod, or transferPeriod.
   - WARN at 30, 14, 7 and 1 days before registry expiry.
   - Treat WHOIS lookup failure as unknown, not as healthy.

d) External HTTP reachability
   - The existing HTTP check runs from the server and can therefore reach
     itself while the world cannot. Keep it, but mark it clearly as an
     internal check.
   - Add an explicit comparison: internal check passing + DNS check failing
     = CRITICAL "reachable locally, unreachable externally". This is the
     exact failure mode that went unnoticed.

e) TLS certificate
   - Expiry countdown against the externally resolved host, not localhost.
   - WARN at 21, 14, 7 and 3 days.

────────────────────────────────
2. TELEGRAM ALERTING
────────────────────────────────
- Config in config/jetgrid.php reading TELEGRAM_BOT_TOKEN,
  TELEGRAM_CHAT_ID, TELEGRAM_ALERTS_ENABLED from .env. No env() outside
  config files.
- Support a per-site chat id override, falling back to the global one, so
  different clients' alerts can go to different groups.
- Send via a queued job. Http::timeout(10)->retry(2, 200). Failures logged,
  never thrown — an alerting failure must not break the scheduler.
- parse_mode HTML, with all interpolated values escaped.

Alert discipline (this matters more than the checks):
- State-change alerts only. Fire on healthy→failing and failing→healthy,
  NOT on every scheduler run. A check that runs every 5 minutes must not
  produce 288 identical messages a day.
- Require N consecutive failures (configurable, default 2) before alerting,
  to suppress transient DNS blips.
- Rate limit: at most one alert per site per check-type per hour, with a
  digest if multiple sites fail together.
- Send an explicit RECOVERED message when a check passes again, including
  how long it was down.
- A daily summary at a configurable time, sent even when everything is
  healthy, so silence is never ambiguous. Silent monitoring is
  indistinguishable from broken monitoring.

Message content must include: site domain, which check failed, the actual
observed value (the NS records, the resolved IP, the status code), the
expected value, and how long it has been failing.

────────────────────────────────
3. LOCAL (WINDOWS) MONITORING
────────────────────────────────
The user develops on Windows and wants to know when local dev services are
down too. Do NOT try to run the Linux monitoring stack on Windows.

Instead:
- Add a lightweight standalone checker (a single PHP or Node script, no
  framework) that runs on the Windows machine, checks a configurable list
  of local URLs/ports, and posts to the same Telegram bot.
- Ship it with a Windows Task Scheduler XML or a documented schtasks
  command for registration.
- Prefix local alerts clearly (e.g. [LOCAL]) so they are never confused
  with production.
- It must be independent — if the AWS box is down, local alerting still
  works, and vice versa.

────────────────────────────────
4. SCHEDULING
────────────────────────────────
- DNS + HTTP: every 5 minutes.
- TLS: every 6 hours.
- WHOIS: once daily, staggered across domains.
- All checks respect JetGrid's read-only guarantees. These are monitoring
  operations on adopted sites — they must work on Adopted — Protected
  resources without needing management mode, and must never write to a
  monitored site.
- No new privileged commands. If `whois` and `dig` are needed, add them to
  the read-only section of CommandRegistry with validated arguments, or
  use PHP's native DNS functions where possible.

────────────────────────────────
5. TESTS
────────────────────────────────
- A domain whose NS records match a suspension pattern produces a CRITICAL
  alert.
- Internal HTTP 200 combined with DNS REFUSED produces the "reachable
  locally, unreachable externally" alert — assert this specifically.
- Repeated identical failures produce exactly one alert, not one per run.
- Recovery produces exactly one RECOVERED message.
- A WHOIS timeout is recorded as unknown, not healthy.
- Monitoring an Adopted — Protected site writes nothing to that site.
- Existing tests, including
  test_god_mode_cannot_write_to_a_protected_site, must still pass.

List every file changed.
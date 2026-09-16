<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use App\Enums\ManagementMode;
use App\Jobs\SendTelegramAlert;
use App\Models\DomainCheckResult;
use App\Models\HealthCheck;
use App\Models\Site;
use App\Services\Alerts\AlertGate;
use App\Services\Reachability\ReachabilityChecker;
use App\Services\Reachability\ReachabilityRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The incident these tests encode: a domain was suspended at the registrar, its
 * nameservers were replaced with a hold host, and the server kept returning
 * HTTP 200 to itself the whole time. Every check passed while the site was
 * unreachable worldwide.
 *
 * Localhost health is not health. These tests assert that JetGrid can now tell
 * the difference.
 */
class ReachabilityMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_IP = '198.51.100.4';

    /** @var array<string,mixed> */
    private array $aResponse;

    /** @var array<string,mixed> */
    private array $nsResponse;

    private bool $dnsUnreachable = false;

    private bool $rdapUnreachable = false;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // Pinned rather than auto-detected so the tests never touch the network
        // to learn what "here" means.
        config()->set('jetgrid.reachability.public_ip', self::SERVER_IP);
        config()->set('jetgrid.telegram.enabled', true);
        config()->set('jetgrid.telegram.bot_token', 'test-token');
        config()->set('jetgrid.telegram.chat_id', '12345');

        $this->aResponse = $this->dohAnswer(0, [self::SERVER_IP]);
        $this->nsResponse = $this->dohAnswer(0, ['ns1.registrar.example.', 'ns2.registrar.example.'], 2);

        /*
         * Registered exactly once. Http::fake() MERGES stubs rather than
         * replacing them and the first match wins, so re-faking mid-test is
         * silently ignored — a test that changed the DNS answer halfway through
         * would keep seeing the original one and pass for the wrong reason.
         * The stubs read mutable state instead.
         */
        Http::fake([
            'cloudflare-dns.com/*' => fn ($request) => $this->resolve($request),
            'dns.google/*' => fn ($request) => $this->resolve($request),
            'rdap.org/*' => function () {
                if ($this->rdapUnreachable) {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }

                return Http::response(['status' => ['active'], 'events' => [], 'nameservers' => []]);
            },
            '*' => Http::response('ok'),
        ]);
    }

    private function resolve($request)
    {
        if ($this->dnsUnreachable) {
            throw new ConnectionException('resolver unreachable');
        }

        return Http::response(
            str_contains($request->url(), 'type=NS') ? $this->nsResponse : $this->aResponse
        );
    }

    private function site(bool $protected = false): Site
    {
        return Site::create([
            'domain' => 'oceanova.example.com',
            'management_mode' => $protected ? ManagementMode::AdoptedProtected : ManagementMode::Managed,
            'is_protected' => $protected,
            'document_root' => '/var/www/oceanova/public',
            'source' => $protected ? 'discovery' : 'jetgrid',
        ]);
    }

    /**
     * @param  list<string>  $records
     * @return array<string,mixed>
     */
    private function dohAnswer(int $status, array $records = [], int $type = 1): array
    {
        return [
            'Status' => $status,
            'Answer' => array_map(fn (string $r) => ['name' => 'x', 'type' => $type, 'data' => $r], $records),
        ];
    }

    /** Both resolvers agree unless a test says otherwise. Safe to call repeatedly. */
    private function fakeDns(array $aResponse, ?array $nsResponse = null): void
    {
        $this->aResponse = $aResponse;

        if ($nsResponse !== null) {
            $this->nsResponse = $nsResponse;
        }
    }

    private function checker(): ReachabilityChecker
    {
        return app(ReachabilityChecker::class);
    }

    private function runner(): ReachabilityRunner
    {
        return app(ReachabilityRunner::class);
    }

    // -----------------------------------------------------------------------

    public function test_nameservers_matching_a_suspension_pattern_produce_a_critical_alert(): void
    {
        $site = $this->site();

        $this->fakeDns(
            $this->dohAnswer(0, [self::SERVER_IP]),
            $this->dohAnswer(0, ['NS1.VERIFICATION-HOLD.SUSPENDED-DOMAIN.COM.'], 2),
        );

        $outcome = $this->checker()->checkNameservers($site);

        $this->assertSame(CheckStatus::Failing, $outcome->status);
        $this->assertSame('critical', $outcome->severity);
        $this->assertStringContainsString('REGISTRAR HOLD', $outcome->summary);
        $this->assertContains('ns1.verification-hold.suspended-domain.com', $outcome->observed['nameservers']);
    }

    /**
     * The exact failure mode that went unnoticed, asserted specifically.
     */
    public function test_internal_http_200_with_dns_refused_is_reported_as_locally_reachable_only(): void
    {
        $site = $this->site();

        // The server is perfectly healthy as far as it can tell.
        HealthCheck::create([
            'site_id' => $site->id,
            'http_status' => 200,
            'response_ms' => 25,
            'ok' => true,
            'checked_at' => now(),
        ]);

        $this->fakeDns($this->dohAnswer(5)); // REFUSED

        $dns = $this->checker()->checkDns($site);
        $external = $this->checker()->checkExternalReachability($site, $dns);

        $this->assertSame(CheckStatus::Failing, $dns->status, 'DNS REFUSED must fail the DNS check.');

        $this->assertSame(CheckStatus::Failing, $external->status);
        $this->assertSame('critical', $external->severity);
        $this->assertStringContainsString('reachable LOCALLY', $external->summary);
        $this->assertStringContainsString('unreachable EXTERNALLY', $external->summary);
        $this->assertSame('passing', $external->observed['internal_http']);
    }

    public function test_repeated_identical_failures_produce_exactly_one_alert(): void
    {
        $site = $this->site();
        $this->fakeDns($this->dohAnswer(3)); // NXDOMAIN, every run

        $sites = Site::where('id', $site->id)->get();

        for ($run = 0; $run < 5; $run++) {
            $this->runner()->run($sites, fn (Site $s) => [$this->checker()->checkDns($s)]);
        }

        // Five runs, five recorded observations — but one message.
        $this->assertSame(5, DomainCheckResult::where('check_type', CheckType::Dns)->count());

        Queue::assertPushed(SendTelegramAlert::class, 1);
    }

    public function test_a_single_failure_is_not_alerted_before_the_threshold(): void
    {
        $site = $this->site();
        $this->fakeDns($this->dohAnswer(3));

        $this->runner()->run(
            Site::where('id', $site->id)->get(),
            fn (Site $s) => [$this->checker()->checkDns($s)],
        );

        // One blip is noise. The default threshold is two consecutive failures.
        Queue::assertNothingPushed();
    }

    public function test_recovery_produces_exactly_one_recovered_message(): void
    {
        $site = $this->site();
        $sites = Site::where('id', $site->id)->get();

        $this->fakeDns($this->dohAnswer(3));

        foreach (range(1, 3) as $ignored) {
            $this->runner()->run($sites, fn (Site $s) => [$this->checker()->checkDns($s)]);
        }

        Queue::assertPushed(SendTelegramAlert::class, 1);

        // Domain comes back.
        $this->fakeDns($this->dohAnswer(0, [self::SERVER_IP]));

        foreach (range(1, 3) as $ignored) {
            $this->runner()->run($sites, fn (Site $s) => [$this->checker()->checkDns($s)]);
        }

        // One failure message plus exactly one recovery message.
        Queue::assertPushed(SendTelegramAlert::class, 2);
    }

    public function test_a_whois_timeout_is_recorded_as_unknown_not_healthy(): void
    {
        $site = $this->site();

        $this->rdapUnreachable = true;

        $outcome = $this->checker()->checkWhois($site);

        $this->assertSame(CheckStatus::Unknown, $outcome->status);
        $this->assertNotSame(CheckStatus::Ok, $outcome->status, 'A failed lookup must never read as healthy.');

        $this->runner()->run(
            Site::where('id', $site->id)->get(),
            fn (Site $s) => [$this->checker()->checkWhois($s)],
        );

        $recorded = DomainCheckResult::where('check_type', CheckType::Whois)->latest('id')->first();
        $this->assertSame(CheckStatus::Unknown, $recorded->status);

        // Indeterminate is not an incident: it must not raise an alarm either.
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_result_does_not_clear_an_existing_failure(): void
    {
        $site = $this->site();
        $sites = Site::where('id', $site->id)->get();

        $this->fakeDns($this->dohAnswer(3));

        foreach (range(1, 2) as $ignored) {
            $this->runner()->run($sites, fn (Site $s) => [$this->checker()->checkDns($s)]);
        }

        Queue::assertPushed(SendTelegramAlert::class, 1);

        // Now every resolver becomes unreachable — we learn nothing.
        $this->dnsUnreachable = true;

        $this->runner()->run($sites, fn (Site $s) => [$this->checker()->checkDns($s)]);

        // No RECOVERED message: the failure was never resolved, only obscured.
        Queue::assertPushed(SendTelegramAlert::class, 1);
    }

    public function test_dns_resolving_to_a_private_address_is_critical(): void
    {
        $site = $this->site();
        $this->fakeDns($this->dohAnswer(0, ['127.0.0.1']));

        $outcome = $this->checker()->checkDns($site);

        $this->assertSame(CheckStatus::Failing, $outcome->status);
        $this->assertSame('critical', $outcome->severity);
        $this->assertStringContainsString('non-public', $outcome->summary);
    }

    public function test_dns_pointing_away_from_this_server_is_critical(): void
    {
        $site = $this->site();
        $this->fakeDns($this->dohAnswer(0, ['203.0.113.9']));

        $outcome = $this->checker()->checkDns($site);

        $this->assertSame(CheckStatus::Failing, $outcome->status);
        $this->assertSame(['203.0.113.9'], $outcome->observed['records']);
        $this->assertSame([self::SERVER_IP], $outcome->expected['records']);
    }

    public function test_nameserver_baseline_is_recorded_then_a_change_is_detected(): void
    {
        $site = $this->site();

        $this->fakeDns(
            $this->dohAnswer(0, [self::SERVER_IP]),
            $this->dohAnswer(0, ['ns1.original.example.', 'ns2.original.example.'], 2),
        );

        $first = $this->checker()->checkNameservers($site);

        $this->assertSame(CheckStatus::Ok, $first->status);
        $this->assertTrue($first->observed['baseline_recorded']);
        $this->assertSame(
            ['ns1.original.example', 'ns2.original.example'],
            $site->fresh()->domainBaseline->nameservers,
        );

        // Registrar quietly repoints the domain.
        $this->fakeDns(
            $this->dohAnswer(0, [self::SERVER_IP]),
            $this->dohAnswer(0, ['ns1.somewhere-else.example.'], 2),
        );

        $second = $this->checker()->checkNameservers($site);

        $this->assertSame(CheckStatus::Failing, $second->status);
        $this->assertSame('critical', $second->severity);
        $this->assertStringContainsString('CHANGED', $second->summary);
        $this->assertSame(['ns1.original.example', 'ns2.original.example'], $second->expected['nameservers']);
    }

    /**
     * Safety constraint #1. Monitoring an adopted site is the entire reason it
     * was adopted — but it must not write one byte to that site's record.
     */
    public function test_monitoring_an_adopted_protected_site_writes_nothing_to_that_site(): void
    {
        $site = $this->site(protected: true);
        $before = $site->fresh()->getAttributes();

        HealthCheck::create([
            'site_id' => $site->id,
            'http_status' => 200,
            'ok' => true,
            'checked_at' => now(),
        ]);

        $this->fakeDns(
            $this->dohAnswer(0, ['203.0.113.9']),
            $this->dohAnswer(0, ['NS1.VERIFICATION-HOLD.SUSPENDED-DOMAIN.COM.'], 2),
        );

        // Everything: the frequent pass, TLS and WHOIS, all against a protected
        // site, all failing loudly. None of it may touch the site row.
        $this->runner()->run(
            Site::where('id', $site->id)->get(),
            fn (Site $s) => array_merge(
                $this->checker()->runFrequentChecks($s),
                [$this->checker()->checkWhois($s)],
            ),
        );

        $this->assertSame(
            $before,
            $site->fresh()->getAttributes(),
            'Reachability monitoring modified a protected site row.',
        );

        // And it genuinely did the work, rather than passing by doing nothing.
        $this->assertGreaterThan(0, DomainCheckResult::where('site_id', $site->id)->count());
    }

    public function test_checks_run_unchanged_while_the_kill_switch_is_on(): void
    {
        config()->set('jetgrid.readonly', true);

        $site = $this->site(protected: true);
        $this->fakeDns($this->dohAnswer(0, [self::SERVER_IP]));

        $outcome = $this->checker()->checkDns($site);

        // Reading DNS is not a write. Read-only mode must not blind the monitor.
        $this->assertSame(CheckStatus::Ok, $outcome->status);
    }

    public function test_telegram_message_escapes_interpolated_values(): void
    {
        $site = $this->site();

        HealthCheck::create([
            'site_id' => $site->id,
            'http_status' => 200,
            'ok' => true,
            'checked_at' => now(),
        ]);

        $this->fakeDns($this->dohAnswer(0, ['<script>alert(1)</script>']));

        $dns = $this->checker()->checkDns($site);
        $decision = app(AlertGate::class)->evaluate($site, $dns);

        // First observation is below the threshold, so force a second.
        $decision = app(AlertGate::class)->evaluate($site, $dns);

        $this->assertNotNull($decision);

        $message = $this->runner()->message($decision);

        $this->assertStringNotContainsString('<script>', $message);
        $this->assertStringContainsString('&lt;script&gt;', $message);
    }
}

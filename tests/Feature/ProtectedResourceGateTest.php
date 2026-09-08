<?php

namespace Tests\Feature;

use App\Enums\ManagementMode;
use App\Enums\Role;
use App\Exceptions\ProtectedResourceException;
use App\Exceptions\ReadOnlyModeException;
use App\Models\Certificate;
use App\Models\CronJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Certificates\CertificateService;
use App\Services\Privilege\CommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Safety constraint #1. These tests are the reason the constraint is
 * architectural rather than a convention: if any of them starts failing, the
 * guarantee made to the operator has been broken.
 */
class ProtectedResourceGateTest extends TestCase
{
    use RefreshDatabase;

    private function adoptedSite(): Site
    {
        return Site::create([
            'domain' => 'live-project.example.com',
            'management_mode' => ManagementMode::AdoptedProtected,
            'is_protected' => true,
            'document_root' => '/var/www/live-project/public',
            'source' => 'discovery',
        ]);
    }

    private function managedSite(): Site
    {
        return Site::create([
            'domain' => 'jetgrid-scratch.example.com',
            'management_mode' => ManagementMode::Managed,
            'is_protected' => false,
            'source' => 'jetgrid',
        ]);
    }

    public function test_monitoring_columns_may_be_updated_on_a_protected_site(): void
    {
        $site = $this->adoptedSite();

        // Adopting a site is pointless if you cannot then monitor it.
        $site->update(['health_score' => 88, 'ram_mb' => 256, 'pending_updates' => 3]);

        $this->assertSame(88, $site->fresh()->health_score);
    }

    public function test_configuration_columns_may_not_be_updated_on_a_protected_site(): void
    {
        $site = $this->adoptedSite();

        $this->expectException(ProtectedResourceException::class);

        $site->update(['domain' => 'stolen.example.com']);
    }

    public function test_a_protected_site_cannot_be_deleted(): void
    {
        $site = $this->adoptedSite();

        $this->expectException(ProtectedResourceException::class);

        $site->delete();
    }

    public function test_god_mode_cannot_write_to_a_protected_site(): void
    {
        $god = User::factory()->create(['role' => Role::GodMode]);
        $this->actingAs($god);

        $site = $this->adoptedSite();

        // The point of the whole design: this is not a permission check, so the
        // most privileged role in the system hits exactly the same wall.
        $this->assertFalse($god->can('update', $site));
        $this->assertFalse($god->can('manageCertificates', $site));

        $this->expectException(ProtectedResourceException::class);

        app(CommandRunner::class)->run('certbot.revoke', ['domain' => $site->domain], $site);
    }

    public function test_certificate_service_refuses_to_issue_for_a_protected_site(): void
    {
        $site = $this->adoptedSite();

        $this->expectException(ProtectedResourceException::class);

        app(CertificateService::class)->issue($site, 'ops@example.com');
    }

    public function test_an_adopted_certificate_cannot_be_renewed_or_revoked(): void
    {
        $site = $this->adoptedSite();

        $certificate = Certificate::create([
            'site_id' => $site->id,
            'domain' => $site->domain,
            'is_managed' => false,
            'not_after' => now()->addDays(5),
        ]);

        $this->assertTrue($certificate->isProtectedResource());

        $this->expectException(ProtectedResourceException::class);

        app(CertificateService::class)->renew($certificate);
    }

    public function test_a_discovered_cron_job_is_locked(): void
    {
        $cron = CronJob::create([
            'schedule' => '0 3 * * *',
            'command' => '/usr/local/bin/backup-live-project.sh',
            'is_protected' => true,
            'source' => 'discovery',
        ]);

        $this->expectException(ProtectedResourceException::class);

        $cron->update(['enabled' => false]);
    }

    public function test_the_refusal_names_the_protected_site_not_the_attempted_value(): void
    {
        $site = $this->adoptedSite();

        try {
            $site->update(['domain' => 'attacker.example.com']);
            $this->fail('Expected the protected gate to refuse.');
        } catch (ProtectedResourceException $e) {
            $this->assertStringContainsString('live-project.example.com', $e->getMessage());
            $this->assertStringNotContainsString('attacker.example.com', $e->getMessage());
        }
    }

    public function test_unprotecting_is_the_only_route_out_and_it_works(): void
    {
        $site = $this->adoptedSite();
        $site->unprotect();

        $this->assertFalse($site->fresh()->is_protected);
        $this->assertSame(ManagementMode::Managed, $site->fresh()->management_mode);

        // Now editable — but still gated by the kill switch for real commands.
        $site->fresh()->update(['document_root' => '/srv/jetgrid/sites/live/public']);
        $this->assertSame('/srv/jetgrid/sites/live/public', $site->fresh()->document_root);
    }

    public function test_the_protected_gate_is_checked_before_the_kill_switch(): void
    {
        // Both would refuse; the protected one must win, because the message the
        // operator sees should be the more fundamental reason.
        $site = $this->adoptedSite();

        $this->expectException(ProtectedResourceException::class);

        app(CommandRunner::class)->run('nginx.reload', [], $site);
    }

    public function test_a_managed_site_hits_the_kill_switch_instead(): void
    {
        $site = $this->managedSite();

        $this->expectException(ReadOnlyModeException::class);

        app(CommandRunner::class)->run('nginx.reload', [], $site);
    }
}

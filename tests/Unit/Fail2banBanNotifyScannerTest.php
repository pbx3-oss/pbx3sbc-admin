<?php

namespace Tests\Unit;

use App\Models\Fail2banBanStat;
use App\Models\Fail2banBlacklist;
use App\Models\Fail2banSetting;
use App\Models\Fail2banWhitelist;
use App\Services\BlacklistSyncService;
use App\Services\Fail2banService;
use App\Services\Ops\Fail2banBanNotifyScanner;
use App\Services\Ops\GatekeeperOpsClient;
use App\Services\PermanentBlacklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class Fail2banBanNotifyScannerTest extends TestCase
{
    use RefreshDatabase;

    private string $statePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statePath = sys_get_temp_dir().'/pbx3-f2b-ban-'.uniqid('', true).'.json';
        config([
            'pbx3_ops.fail2ban_ban_notify_enabled' => true,
            'pbx3_ops.state_path' => $this->statePath,
            'pbx3_ops.max_emits_per_run' => 10,
            'pbx3_ops.sbc_fqdn' => 'sbc.pbx3.com',
            'pbx3_ops.gatekeeper_url' => 'https://control.example',
            'pbx3_ops.gatekeeper_token' => 'tok',
        ]);
        Fail2banSetting::query()->create(['auto_perma_threshold' => 10]);
        Fail2banSetting::forgetCache();
    }

    protected function tearDown(): void
    {
        @unlink($this->statePath);
        Mockery::close();
        parent::tearDown();
    }

    private function scanner(
        Fail2banService $fail2ban,
        GatekeeperOpsClient $gk,
        ?PermanentBlacklistService $permanent = null,
    ): Fail2banBanNotifyScanner {
        $permanent ??= new PermanentBlacklistService(
            Mockery::mock(BlacklistSyncService::class, function ($m) {
                $m->shouldReceive('sync')->andReturn(true);
            })
        );

        return new Fail2banBanNotifyScanner($fail2ban, $gk, $permanent);
    }

    public function test_first_run_seeds_without_emit_or_stats(): void
    {
        $fail2ban = Mockery::mock(Fail2banService::class);
        $fail2ban->shouldReceive('getStatus')->once()->andReturn([
            'jail_name' => 'opensips-brute-force',
            'service_running' => true,
            'banned_ips' => ['203.0.113.1'],
            'currently_banned' => 1,
        ]);

        $gk = Mockery::mock(GatekeeperOpsClient::class);
        $gk->shouldNotReceive('postEvent');

        $result = $this->scanner($fail2ban, $gk)->run();

        $this->assertTrue($result['seeded']);
        $this->assertSame(0, $result['emitted']);
        $this->assertSame(0, $result['stats_incremented']);
        $this->assertSame(1, $result['current']);
        $this->assertFileExists($this->statePath);
        $this->assertSame(0, Fail2banBanStat::count());
    }

    public function test_new_ban_emits_event_and_increments_stats(): void
    {
        file_put_contents($this->statePath, json_encode([
            'banned' => ['203.0.113.1'],
            'updated_at' => gmdate('c'),
        ]));

        $fail2ban = Mockery::mock(Fail2banService::class);
        $fail2ban->shouldReceive('getStatus')->once()->andReturn([
            'jail_name' => 'opensips-brute-force',
            'service_running' => true,
            'banned_ips' => ['203.0.113.1', '198.51.100.9'],
            'currently_banned' => 2,
        ]);

        $gk = Mockery::mock(GatekeeperOpsClient::class);
        $gk->shouldReceive('isConfigured')->once()->andReturn(true);
        $gk->shouldReceive('postEvent')->once()->with(Mockery::on(function (array $event): bool {
            return ($event['type'] ?? '') === 'fail2ban_ban'
                && ($event['source_ip'] ?? '') === '198.51.100.9'
                && ($event['sbc_fqdn'] ?? '') === 'sbc.pbx3.com';
        }))->andReturn(['accepted' => true, 'notified' => true]);

        $result = $this->scanner($fail2ban, $gk)->run();

        $this->assertFalse($result['seeded']);
        $this->assertSame(1, $result['new']);
        $this->assertSame(1, $result['emitted']);
        $this->assertSame(1, $result['stats_incremented']);
        $this->assertSame(1, Fail2banBanStat::where('ip', '198.51.100.9')->value('ban_count'));
    }

    public function test_notify_off_still_increments_stats(): void
    {
        config(['pbx3_ops.fail2ban_ban_notify_enabled' => false]);
        file_put_contents($this->statePath, json_encode([
            'banned' => [],
            'updated_at' => gmdate('c'),
        ]));

        $fail2ban = Mockery::mock(Fail2banService::class);
        $fail2ban->shouldReceive('getStatus')->once()->andReturn([
            'jail_name' => 'opensips-brute-force',
            'service_running' => true,
            'banned_ips' => ['203.0.113.50'],
            'currently_banned' => 1,
        ]);

        $gk = Mockery::mock(GatekeeperOpsClient::class);
        $gk->shouldNotReceive('isConfigured');
        $gk->shouldNotReceive('postEvent');

        $result = $this->scanner($fail2ban, $gk)->run();

        $this->assertFalse($result['notify_enabled']);
        $this->assertSame(1, $result['stats_incremented']);
        $this->assertSame(0, $result['emitted']);
        $this->assertSame(1, Fail2banBanStat::where('ip', '203.0.113.50')->value('ban_count'));
    }

    public function test_auto_promote_at_threshold(): void
    {
        Fail2banSetting::current()->update(['auto_perma_threshold' => 2]);
        Fail2banSetting::forgetCache();

        file_put_contents($this->statePath, json_encode([
            'banned' => [],
            'updated_at' => gmdate('c'),
        ]));
        Fail2banBanStat::query()->create([
            'ip' => '198.51.100.77',
            'ban_count' => 1,
            'first_banned_at' => now()->subDay(),
            'last_banned_at' => now()->subDay(),
        ]);

        $fail2ban = Mockery::mock(Fail2banService::class);
        $fail2ban->shouldReceive('getStatus')->once()->andReturn([
            'jail_name' => 'opensips-brute-force',
            'service_running' => true,
            'banned_ips' => ['198.51.100.77'],
            'currently_banned' => 1,
        ]);

        $gk = Mockery::mock(GatekeeperOpsClient::class);
        $gk->shouldReceive('isConfigured')->andReturn(true);
        $gk->shouldReceive('postEvent')->andReturn(['accepted' => true]);

        $result = $this->scanner($fail2ban, $gk)->run();

        $this->assertSame(1, $result['promoted']);
        $this->assertSame(2, Fail2banBanStat::where('ip', '198.51.100.77')->value('ban_count'));
        $row = Fail2banBlacklist::where('ip_or_cidr', '198.51.100.77')->first();
        $this->assertNotNull($row);
        $this->assertSame(Fail2banBlacklist::SOURCE_AUTO, $row->source);
        $this->assertSame(2, $row->ban_count_at_promote);
    }

    public function test_threshold_zero_disables_promote(): void
    {
        Fail2banSetting::current()->update(['auto_perma_threshold' => 0]);
        Fail2banSetting::forgetCache();

        file_put_contents($this->statePath, json_encode(['banned' => []]));
        Fail2banBanStat::query()->create([
            'ip' => '198.51.100.88',
            'ban_count' => 99,
            'first_banned_at' => now(),
            'last_banned_at' => now(),
        ]);

        $fail2ban = Mockery::mock(Fail2banService::class);
        $fail2ban->shouldReceive('getStatus')->once()->andReturn([
            'service_running' => true,
            'banned_ips' => ['198.51.100.88'],
            'currently_banned' => 1,
        ]);

        $gk = Mockery::mock(GatekeeperOpsClient::class);
        $gk->shouldReceive('isConfigured')->andReturn(true);
        $gk->shouldReceive('postEvent')->andReturn(['accepted' => true]);

        $result = $this->scanner($fail2ban, $gk)->run();

        $this->assertSame(0, $result['promoted']);
        $this->assertSame(0, Fail2banBlacklist::count());
    }

    public function test_whitelist_skips_auto_promote(): void
    {
        Fail2banSetting::current()->update(['auto_perma_threshold' => 1]);
        Fail2banSetting::forgetCache();
        Fail2banWhitelist::query()->create([
            'ip_or_cidr' => '198.51.100.99',
            'comment' => 'lab',
        ]);

        file_put_contents($this->statePath, json_encode(['banned' => []]));

        $fail2ban = Mockery::mock(Fail2banService::class);
        $fail2ban->shouldReceive('getStatus')->once()->andReturn([
            'service_running' => true,
            'banned_ips' => ['198.51.100.99'],
            'currently_banned' => 1,
        ]);

        $gk = Mockery::mock(GatekeeperOpsClient::class);
        $gk->shouldReceive('isConfigured')->andReturn(true);
        $gk->shouldReceive('postEvent')->andReturn(['accepted' => true]);

        $result = $this->scanner($fail2ban, $gk)->run();

        $this->assertSame(0, $result['promoted']);
        $this->assertSame(0, Fail2banBlacklist::count());
        $this->assertSame(1, Fail2banBanStat::where('ip', '198.51.100.99')->value('ban_count'));
    }
}

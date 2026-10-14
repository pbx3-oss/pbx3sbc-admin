<?php

namespace App\Services;

use App\Models\Fail2banBlacklist;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class BlacklistSyncService
{
    protected function getSyncScriptPath(): string
    {
        $scriptPath = env('FAIL2BAN_BLACKLIST_SYNC_SCRIPT_PATH');

        if ($scriptPath && file_exists($scriptPath)) {
            return $scriptPath;
        }

        $commonPaths = [
            '/home/ubuntu/pbx3sbc/scripts/sync-fail2ban-blacklist.sh',
            '/home/tech/pbx3sbc/scripts/sync-fail2ban-blacklist.sh',
            '/opt/pbx3sbc/scripts/sync-fail2ban-blacklist.sh',
            '/usr/local/pbx3sbc/scripts/sync-fail2ban-blacklist.sh',
            base_path('../pbx3sbc/scripts/sync-fail2ban-blacklist.sh'),
        ];

        foreach ($commonPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        foreach (glob('/home/*/pbx3sbc/scripts/sync-fail2ban-blacklist.sh') ?: [] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return '/home/ubuntu/pbx3sbc/scripts/sync-fail2ban-blacklist.sh';
    }

    public function sync(): bool
    {
        $credsFile = null;

        try {
            $scriptPath = $this->getSyncScriptPath();

            if (! file_exists($scriptPath)) {
                Log::error('Fail2Ban blacklist sync script not found', ['path' => $scriptPath]);

                return false;
            }

            $dbName = config('database.connections.mysql.database');
            $dbUser = config('database.connections.mysql.username');
            $dbPass = config('database.connections.mysql.password');

            $credsFile = $this->writeMysqlDefaultsExtraFile($dbUser, $dbPass);
            if ($credsFile === null) {
                Log::error('Failed to write MySQL credentials file for Fail2Ban blacklist sync');

                return false;
            }

            $result = Process::run([
                'sudo',
                $scriptPath,
                $dbName,
                $credsFile,
            ]);

            if (! $result->successful()) {
                Log::error('Failed to sync Fail2Ban blacklist', [
                    'script_path' => $scriptPath,
                    'exit_code' => $result->exitCode(),
                    'error_output' => $result->errorOutput(),
                    'output' => $result->output(),
                ]);

                return false;
            }

            Log::info('Fail2Ban permanent blacklist synced', [
                'script_path' => $scriptPath,
                'ip_count' => Fail2banBlacklist::count(),
                'user' => auth()->id(),
                'output' => $result->output(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to sync Fail2Ban blacklist', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        } finally {
            if ($credsFile !== null && file_exists($credsFile)) {
                @unlink($credsFile);
            }
        }
    }

    protected function writeMysqlDefaultsExtraFile(?string $dbUser, ?string $dbPass): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'f2bbl');
        if ($path === false) {
            return null;
        }

        if (! chmod($path, 0600)) {
            @unlink($path);

            return null;
        }

        $contents = "[client]\n"
            .'user='.($dbUser ?? '')."\n"
            .'password='.($dbPass ?? '')."\n";

        if (file_put_contents($path, $contents) === false) {
            @unlink($path);

            return null;
        }

        return $path;
    }
}

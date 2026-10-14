<?php

use App\Services\Retention\AccPurgeService;
use App\Services\Retention\SecurityEventsPurgeService;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(\Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pbx3sbc:purge-security-events {--days= : Override local_days (default 30)} {--batch= : Rows per DELETE} {--dry-run : Count eligible only; do not delete}', function (
    SecurityEventsPurgeService $service,
) {
    $days = $this->option('days') !== null ? (int) $this->option('days') : null;
    $batch = $this->option('batch') !== null ? (int) $this->option('batch') : null;
    $dryRun = (bool) $this->option('dry-run');

    $result = $service->run($days, $batch, $dryRun);

    $this->info(($dryRun ? '[dry-run] ' : '').'Security events purge (local_days='.$result['days'].')');
    foreach ($result['tables'] as $row) {
        $this->line(sprintf(
            '  %s: eligible=%d deleted=%d batches=%d cutoff=%s',
            $row['table'],
            $row['eligible'],
            $row['deleted'],
            $row['batches'],
            $row['cutoff'],
        ));
    }
})->purpose('Purge door_knock_attempts + failed_registrations older than retention (WS1)');

Artisan::command('pbx3sbc:purge-acc {--days= : Override local_days (default 90)} {--batch= : Rows per DELETE} {--dry-run : Count eligible only; do not delete}', function (
    AccPurgeService $service,
) {
    $days = $this->option('days') !== null ? (int) $this->option('days') : null;
    $batch = $this->option('batch') !== null ? (int) $this->option('batch') : null;
    $dryRun = (bool) $this->option('dry-run');

    $result = $service->run($days, $batch, $dryRun);

    $this->info(($dryRun ? '[dry-run] ' : '').'acc purge (local_days='.$result['days'].')');
    foreach ($result['tables'] as $row) {
        $this->line(sprintf(
            '  %s: eligible=%d deleted=%d batches=%d cutoff=%s',
            $row['table'],
            $row['eligible'],
            $row['deleted'],
            $row['batches'],
            $row['cutoff'],
        ));
    }
})->purpose('Purge OpenSIPS acc (edge CDR) older than retention (WS2)');

Artisan::command('pbx3sbc:ops-fail2ban-bans', function (
    \App\Services\Ops\Fail2banBanNotifyScanner $scanner,
) {
    $result = $scanner->run();
    $this->info(sprintf(
        'notify=%s seeded=%s current=%d new=%d stats=%d promoted=%d emitted=%d skipped_cap=%d errors=%d',
        ($result['notify_enabled'] ?? false) ? 'on' : 'off',
        $result['seeded'] ? 'yes' : 'no',
        $result['current'],
        $result['new'],
        $result['stats_incremented'] ?? 0,
        $result['promoted'] ?? 0,
        $result['emitted'],
        $result['skipped_cap'],
        count($result['errors'])
    ));
    foreach ($result['errors'] as $err) {
        $this->warn($err);
    }

    return count($result['errors']) > 0 && ($result['emitted'] + ($result['promoted'] ?? 0) + ($result['stats_incremented'] ?? 0)) === 0 ? 1 : 0;
})->purpose('Diff Fail2ban bans: ban_stats + auto-promote; optional Gatekeeper notify');

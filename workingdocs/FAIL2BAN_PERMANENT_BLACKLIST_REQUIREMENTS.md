# Fail2ban permanent blacklist + auto-promote

**Status:** locked (2026-10-14)  
**Repos:** `pbx3sbc-admin` (Filament + DB) · `pbx3sbc` (sync script + sudoers)

## Permanent vs short-term

| | Short-term (Fail2ban jail) | Permanent blacklist |
|--|---------------------------|---------------------|
| Store | Fail2ban runtime | `fail2ban_blacklist` table |
| Enforce | Jail action (UDP/5060) | iptables chain `PBX3SBC-BLACKLIST` **DROP all** from IP/CIDR |
| Lifetime | `bantime` (e.g. 1h) | Until removed in admin |
| UI | Fail2Ban → Status | Fail2Ban → Permanent blacklist |

Permanent rules survive Fail2ban unban/restart. Whitelist (`fail2ban_whitelist` / jail `ignoreip`) is unrelated: permanent DROP still applies unless the operator removes the blacklist row.

## Auto-promote

- Track **lifetime** temp-ban observations per IP in `fail2ban_ban_stats`.
- When `ban_count >= auto_perma_threshold` (default **10**), insert blacklist (`source=auto`) and sync firewall.
- Threshold **0** disables auto-promote.
- **Never** auto-promote IPs present in `fail2ban_whitelist`.
- Counting runs on every `pbx3sbc:ops-fail2ban-bans` cron tick (even when Gatekeeper ban-notify email is off).

## Manual permanent ban / unban

- **Permanent blacklist** page is home of record (like Whitelist): CRUD, Sync, threshold, **Remove permanent ban**.
- Status stays short-term jail focused: **Ban permanently** on a currently banned IP (and optional Manual Ban → also permanent). Count + link to Permanent blacklist; no full permanent list on Status.
- Remove deletes the DB row and re-syncs iptables (accidental bans are reversible).

## Sync

- Script: `pbx3sbc/scripts/sync-fail2ban-blacklist.sh` (DB creds-file pattern; no password on argv).
- Sudoers: www-data NOPASSWD for that script.

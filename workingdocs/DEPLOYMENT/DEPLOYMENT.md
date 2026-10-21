# Deployment Guide

**Last Updated:** 2026-01-22  
**Purpose:** Deployment guide for local and remote installations

## Overview

This guide covers deployment of the PBX3SBC Admin Panel, including local installation and remote deployment on a separate server from the OpenSIPS server.

---

## Local Installation

### Automated Installation

The project includes a fully automated installer script:

```bash
sudo ./install.sh [--db-host HOST] [--db-user USER] [--db-password PASSWORD] [--db-name NAME] [--admin-name NAME] [--admin-email EMAIL] [--admin-password PASSWORD]
```

**Features:**
- ✅ Auto-installs PHP 8.2+ (detects Ubuntu version, prioritizes PHP 8.3 for Ubuntu 24.04)
- ✅ Auto-installs PHP extensions (pdo, pdo_mysql, mbstring, xml, curl, zip, bcmath, intl)
- ✅ Auto-installs PHP-FPM
- ✅ Auto-installs Nginx
- ✅ Auto-installs Composer
- ✅ Auto-configures Nginx with Laravel-optimized settings
- ✅ Auto-configures PHP-FPM socket detection
- ✅ Installs PHP-FPM drop-in **`session.upload_progress.enabled = Off`** (`deploy/php/99-pbx3sbc-session.ini` via `scripts/apply-php-session-hardening.sh`; tip-safe). Product note: pbx3 **`PHP_SESSION_UPLOAD_PROGRESS_STOPGAP.md`**.
- ✅ Auto-configures database connection
- ✅ Auto-sets file permissions
- ✅ Handles Composer lock file incompatibilities
- ✅ Non-interactive admin user creation (generates random password)

**Usage:**
```bash
# Install with default settings
sudo ./install.sh

# Install with custom database
sudo ./install.sh --db-host 192.168.1.58 --db-user opensips --db-password password --db-name opensips

# Install with custom admin user
sudo ./install.sh --admin-name "Admin" --admin-email "admin@example.com" --admin-password "password"
```

### Manual Installation Steps

If you prefer manual installation, see `INSTALLATION-LOG.md` in the archive folder for detailed step-by-step commands.

---

## Remote Deployment

### Architecture

```
┌─────────────────────────────────────┐
│   OpenSIPS Server                   │
│                                     │
│   ┌─────────────────────────────┐  │
│   │  OpenSIPS                   │  │
│   │  MySQL Database             │  │
│   │  OpenSIPS MI HTTP (8888)    │  │
│   └───────────┬─────────────────┘  │
└───────────────┼─────────────────────┘
                │
                │ Network Connection
                │
┌───────────────┼─────────────────────┐
│   Admin Panel Server                │
│                                     │
│   ┌─────────────────────────────┐  │
│   │  Laravel + Filament         │  │
│   │  Admin Panel                │  │
│   └─────────────────────────────┘  │
│                                     │
│   Connections:                      │
│   - MySQL (3306/tcp) → OpenSIPS    │
│   - HTTP MI (8888/tcp) → OpenSIPS  │
│   - HTTP/HTTPS (80/443) → Users    │
└─────────────────────────────────────┘
```

### Requirements

#### 1. Database Connectivity (Required)

**On OpenSIPS Server (MySQL Server):**

1. **Enable Remote MySQL Access**

Edit MySQL configuration:
```bash
# Ubuntu/Debian
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf

# Change:
bind-address = 0.0.0.0

# Restart MySQL
sudo systemctl restart mysql
```

2. **Grant Remote Access to MySQL User**

```sql
-- Option 1: Allow from specific IP (recommended)
GRANT ALL PRIVILEGES ON opensips.* TO 'opensips'@'admin-panel-server-ip' IDENTIFIED BY 'password';
FLUSH PRIVILEGES;

-- Option 2: Allow from any IP (less secure)
GRANT ALL PRIVILEGES ON opensips.* TO 'opensips'@'%' IDENTIFIED BY 'password';
FLUSH PRIVILEGES;
```

3. **Configure Firewall**

```bash
# Allow MySQL port from admin panel server
sudo ufw allow from admin-panel-server-ip to any port 3306
```

**On Admin Panel Server:**

Configure `.env` file:
```env
DB_CONNECTION=mysql
DB_HOST=192.168.1.58  # OpenSIPS server IP
DB_PORT=3306
DB_DATABASE=opensips
DB_USERNAME=opensips
DB_PASSWORD=password
```

Test connection:
```bash
php artisan db:show
```

#### 2. OpenSIPS MI Connectivity (Optional)

**On OpenSIPS Server:**

1. **Configure OpenSIPS MI HTTP Interface**

In `opensips.cfg`:
```
loadmodule "mi_http.so"
modparam("mi_http", "http_root", "/mi")
modparam("mi_http", "http_port", 8888)
```

2. **Configure Firewall**

```bash
# Allow MI port from admin panel server
sudo ufw allow from admin-panel-server-ip to any port 8888
```

**On Admin Panel Server:**

Configure `.env` file:
```env
OPENSIPS_MI_URL=http://192.168.1.58:8888/mi
```

**Note:** MI integration is optional. The admin panel works without it, but OpenSIPS modules won't auto-reload after database changes.

### Security Considerations

1. **MySQL Security:**
   - Use strong passwords
   - Restrict MySQL access to specific IP (not `%`)
   - Use SSL/TLS for MySQL (production)
   - Use private network/VPN (preferred)

2. **OpenSIPS MI Security:**
   - Restrict MI access to admin panel server only
   - Consider authentication if MI supports it
   - Use private network/VPN

3. **Admin Panel Security:**
   - Use HTTPS in production
   - Keep Laravel and dependencies updated
   - Use strong admin passwords
   - Regular security updates

### Deployment Checklist

**On OpenSIPS Server:**
- [ ] MySQL configured to accept remote connections (`bind-address = 0.0.0.0`)
- [ ] MySQL user granted remote access permissions
- [ ] Firewall configured to allow MySQL port 3306 from admin panel server
- [ ] Firewall configured to allow OpenSIPS MI port 8888 from admin panel server (if using)
- [ ] MySQL remote access tested from admin panel server
- [ ] OpenSIPS MI HTTP interface tested (if using)

**On Admin Panel Server:**
- [ ] Admin panel application installed (Laravel + Filament)
- [ ] `.env` file configured with remote database host
- [ ] `.env` file configured with remote OpenSIPS MI URL (if using)
- [ ] Database connection tested: `php artisan db:show`
- [ ] Laravel migrations run: `php artisan migrate`
- [ ] Admin user created: `php artisan make:filament-user`
- [ ] Web server configured (nginx/apache)
- [ ] SSL certificate installed (production)

**Verification:**
- [ ] Admin panel accessible via web browser
- [ ] Can log in with admin credentials
- [ ] Call Routes management (CRUD operations) works
- [ ] Data persists correctly in database
- [ ] (Optional) OpenSIPS MI reload actions work (if MI service implemented)

---

## Development Setup

### Local Development (Mac with Herd)

1. **Link to Herd:**
   ```bash
   herd link pbx3sbc-admin
   ```

2. **Access:** `http://pbx3sbc-admin.test/admin`

3. **Database:** Configure `.env` to point to MySQL (local or remote)

### Development Stack Recommendations

See `DEVELOPMENT-STACK-RECOMMENDATIONS.md` for detailed development environment setup.

---

## Common Issues

### Database Connection Issues

**"Host '127.0.0.1' is not allowed to connect"**
- **Cause:** MySQL user doesn't have permission for `127.0.0.1`
- **Solution:** Grant permissions for both `localhost` and `127.0.0.1`:
  ```sql
  GRANT ALL PRIVILEGES ON opensips.* TO 'opensips'@'localhost' IDENTIFIED BY 'password';
  GRANT ALL PRIVILEGES ON opensips.* TO 'opensips'@'127.0.0.1' IDENTIFIED BY 'password';
  FLUSH PRIVILEGES;
  ```
- **Note:** MySQL treats `localhost` (Unix socket) and `127.0.0.1` (TCP) as different hosts

### Nginx Configuration

**Nginx not serving Laravel correctly**
- **Check:** `/etc/nginx/sites-available/pbx3sbc-admin`
- **Verify:** PHP-FPM socket path matches installed PHP version
- **Test:** `sudo nginx -t`
- **Reload:** `sudo systemctl reload nginx`

### Storage Permissions

**"Permission denied" errors in storage/logs**
- **Solution:**
  ```bash
  sudo chown -R www-data:www-data storage bootstrap/cache
  sudo chmod -R 775 storage bootstrap/cache
  ```

---

## Related Documentation

- `../ARCHITECTURE/PROJECT-CONTEXT.md` - Project overview and architecture
- `../QUICK-REFERENCES/CURRENT-STATE.md` - Current implementation status
- `../ARCHITECTURE/ARCHITECTURE.md` - System architecture

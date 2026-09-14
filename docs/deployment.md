# SaferNET Deployment & Operations Guide

## 1. Deployment Checklist

Deploying SaferNET across a school computer lab involves three sequential stages:
1. **County Portal Setup**: Registering the institution, defining classrooms/laboratories, importing learner rosters, and configuring baseline content policies.
2. **Device Provisioning & Token Issuance**: Enrolling workstation asset tags and generating scoped service credentials.
3. **Endpoint Client Installation**: Installing the Windows DNS Agent and deploying the Chrome extension via administrative policies.

---

## 2. Server & Backend Installation

### System Requirements
- **Server**: Ubuntu 24.04 LTS / Debian 12 / Rocky Linux 9
- **PHP**: PHP 8.4+ with extensions: `pdo_pgsql`, `mbstring`, `intl`, `gd`, `zip`, `bcmath`, `curl`
- **Database**: PostgreSQL 17+
- **Node.js**: v22+ and npm
- **Web Server**: Nginx with PHP-FPM

### Quickstart Setup
```bash
# Clone the repository
git clone https://github.com/isaacmuchunu/SaferNET.git /var/www/safernet
cd /var/www/safernet

# Install backend dependencies
composer install --no-dev --optimize-autoloader

# Install frontend dependencies & compile production assets
npm ci
npm run build

# Configure environment
cp .env.example .env
php artisan key:generate

# Configure database in .env, then run migrations and baseline seeds
php artisan migrate --force
php artisan db:seed --class=CountyDirectorSeeder --force
php artisan db:seed --class=DefaultContentCategoriesSeeder --force

# Verify system health
php artisan safernet:doctor
```

---

## 3. School Onboarding Workflow

### Step 1: Enroll Institution & Workstations
Run on the server CLI or through the CDE Officer Portal:
```bash
# Enroll a school workstation
php artisan safernet:enroll-device <NEMIS_CODE> LAB-01-PC-01 --laboratory="Computer Lab 1"
```

### Step 2: Issue Scoped Service Token
```bash
# Generates a long-lived bearer token restricted to device telemetry and policy sync
php artisan safernet:issue-service-token <NEMIS_CODE> --name="agent-token-LAB-01-PC-01"
```
*Note: Securely store the output token string. It is hashed in the database and shown only once.*

---

## 4. Workstation Client Installation

On each school lab workstation running Windows 10/11:

1. Download or copy `SaferNET-Agent-win-x64.zip` to `C:\Temp\SaferNET`.
2. Extract the archive.
3. Open PowerShell as **Administrator** and run:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy RemoteSigned

.\install.ps1 `
    -ApiBaseUrl "https://safernet.kiambu.go.ke/api/v1" `
    -ServiceToken "YOUR_SERVICE_TOKEN" `
    -ManagedDeviceId "101" `
    -WorkstationId "LAB-01-PC-01" `
    -ConfigureDns `
    -ExtensionId "YOUR_EXTENSION_STORE_ID" `
    -ForceInstallExtension
```

---

## 5. Verification & Acceptance Testing

Verify that protection is active on the workstation:

### Test 1: DNS Resolution & Filtering
```powershell
# Allowed domain - should resolve via upstream
Resolve-DnsName -Name "education.go.ke" -Server 127.0.0.1

# Blocked domain - should return NXDOMAIN or fail immediately
Resolve-DnsName -Name "gambling.example" -Server 127.0.0.1
```

### Test 2: In-Browser Extension Verification
1. Open Google Chrome.
2. Verify the SaferNET Shield icon is visible in the toolbar.
3. Navigate to a known prohibited domain. Confirm the request redirects to `blocked.html`.
4. Submit an exception request from the block page and verify the request appears in the School Head's portal queue.

---

## 6. Maintenance & Operational Tasks

### Automated Scheduled Tasks (Cron / Systemd)
Add to the server's crontab (`crontab -e`):
```bash
* * * * * cd /var/www/safernet && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler automatically executes:
- `safernet:expire-stale-components`: Flags devices as degraded/offline if heartbeats cease.
- `safernet:sync-blocklists`: Syncs updated county blocklist definitions and feeds.
- `safernet:triage-domains`: Runs AI classifier on unclassified browsing telemetry.

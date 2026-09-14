# SaferNET Security, Privacy & Compliance

## 1. Compliance Framework & Mandate

SaferNET is built to adhere to statutory and regulatory requirements for educational technology and child safeguarding in Kenya:
- **Kenya Data Protection Act (KDPA) 2019**: Processing of personal data concerning children, data minimization, lawful processing, and right to privacy.
- **Basic Education Act & Ministry of Education Guidelines**: Digital learning environment safety, child protection against harmful, violent, extremist, or sexually explicit material.
- **National Cybersecurity Strategy**: Endpoint security integrity, resilience against malware/C2 traffic, and tamper-resistant logging.

---

## 2. Child Privacy & Data Minimization Principles

### 2.1 Credential & PIN Security
- **No Plaintext Storage**: Learner PINs (4 digits) are never stored in plaintext. They are hashed using robust bcrypt cost factors (`pin_hash`).
- **Zero Log Leakage**: Authentication controllers and middleware strictly sanitize payloads. PINs are scrubbed before writing to `audit_logs` or application debug logs.
- **Brute-Force Lockout**: Dual-axis rate limiting prevents PIN guessing attacks:
  - Workstation rate limit: Maximum 5 attempts per workstation per window.
  - Learner rate limit: Prevents coordinated brute-forcing across multiple machines.

### 2.2 Telemetry Minimization
- The platform records top-level navigational events (`domain`, `action`, `occurred_at`, `category`, `learner_id`).
- Intrusive payloads, form submissions, query strings containing credentials, and private communication contents are never collected or transmitted.

---

## 3. Network & System Security

### 3.1 Cryptographic Transport
- All API interactions between endpoints (Agent, Extension) and central servers enforce TLS 1.3 encryption.
- Direct database connections utilize PostgreSQL SSL verification.

### 3.2 Endpoint Privilege Separation
- The Windows Service runs under the local `NT AUTHORITY\SYSTEM` account.
- Configuration files (`appsettings.json`) holding API tokens are protected with restrictive Windows ACLs (`SYSTEM:F`, `Administrators:F`), preventing unprivileged student accounts from reading credentials.

### 3.3 Multi-Tenant Isolation
- Strict database constraints guarantee that no school can view another institution's devices, learners, or telemetry.
- County officers possess designated read/audit scopes with detailed audit logging for every policy change.

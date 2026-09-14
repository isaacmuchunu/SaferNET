# SaferNET API Reference Guide

## 1. Overview & Authentication

All API endpoints reside under the `/api/v1` namespace.

### Authentication Schemes
- **Sanctum Bearer Token (`portal:access`)**: Used by County Directors, Sub-County Directors, and Heads of Institutions accessing the management portal.
- **Service Token (`telemetry:write`)**: Issued per institution or device. Used by the Windows Agent and Browser Extension for heartbeat, synchronization, and event reporting.

---

## 2. Endpoint Index

### 2.1 Client Synchronization & Telemetry
- `GET /api/v1/extension/sync`: Fetches the current effective filtering policy, active category blocklists, and dynamic rules for the institution.
- `POST /api/v1/extension/heartbeat`: Reports extension status, installed rule count, and telemetry queue size.
- `POST /api/v1/extension/telemetry`: Batch ingestion of learner web browsing events.
- `GET /api/v1/agent/policy`: Fetches the authoritative DNS blocklist hash and domain list for the Windows agent.
- `POST /api/v1/agent/events`: Reports blocked DNS query attempts from the agent.

### 2.2 Workstation Session & Learner Authentication
- `POST /api/v1/extension/sign-in`: Authenticates a learner at a workstation using learner admission number and 4-digit PIN.
- `POST /api/v1/extension/sign-out`: Terminates the active learner session at the workstation.
- `GET /api/v1/extension/session`: Returns the active learner session assigned to the workstation.

### 2.3 County Governance & Domain Triage
- `GET /api/v1/domain-reviews`: Lists unclassified or flagged domains awaiting review.
- `PUT /api/v1/domain-reviews/{id}`: Approves, blocks, or recategorizes a domain.
- `POST /api/v1/filtering/assess`: Queries the automated domain content classifier.

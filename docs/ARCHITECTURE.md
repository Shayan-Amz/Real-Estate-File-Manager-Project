# Architecture Reference

> Complete technical reference for the Amlak-e-Man platform architecture.

---

## Table of Contents

- [System Overview](#system-overview)
- [Database Schema](#database-schema)
- [API Reference](#api-reference)
- [Authentication Model](#authentication-model)
- [Data Flow](#data-flow)
- [Jarvis AI Pipeline](#jarvis-ai-pipeline)
- [Frontend Architecture](#frontend-architecture)
- [PWA & Service Worker](#pwa--service-worker)
- [Security Architecture](#security-architecture)
- [Deployment Topology](#deployment-topology)

---

## System Overview

Amlak-e-Man follows a **monolithic server-rendered architecture** optimized for shared hosting environments. The entire application runs on a single Apache server with a MySQL database, requiring no Node.js, Docker, or container orchestration.

### Design Principles

1. **Zero external dependencies** — No Composer packages, no npm, no build step
2. **Shared-hosting compatible** — Runs on basic cPanel/DirectAdmin plans
3. **Single-file deployment** — Frontend is a single `index.html` with inline CSS and JS
4. **Auto-migration** — Database schema evolves automatically on each API request
5. **Recovery-first** — Built-in diagnostics panel and service worker fallback

### Request Lifecycle

```
Browser → Service Worker → Apache → .htaccess → api.php → MySQL
                                        ↓
                                  stt.php (voice requests)
                                        ↓
                              External AI (HF/Groq/OpenAI/OpenRouter)
```

---

## Database Schema

The database uses **MySQL 5.7+ / MariaDB 10.3+** with `utf8mb4` encoding and `InnoDB` engine. Schema versioning is managed via `sys_config.schema_version` with auto-incrementing migration steps.

### Table: `agencies`

The tenant registry. Each row represents one real estate agency.

| Column | Type | Description |
|--------|------|-------------|
| `id` | VARCHAR(50) PK | 6-digit agency code (randomly generated) |
| `name` | VARCHAR(191) | Agency display name |
| `city` | VARCHAR(100) | Agency city |
| `phone` | VARCHAR(20) | Primary phone number |
| `phone2` | VARCHAR(20) | Secondary phone (optional) |
| `managerName` | VARCHAR(100) | Name of the agency manager |
| `adminPin` | VARCHAR(100) | Manager login PIN (plaintext or bcrypt legacy) |
| `masterPass` | VARCHAR(100) | Master password for agency operations |
| `plan_type` | ENUM('basic','pro','vip') | Subscription tier |
| `createdAt` | DATETIME | Agency creation timestamp |
| `expireAt` | DATETIME | Subscription expiry date |

### Table: `properties`

Property listings — the core data entity with **40+ columns**.

| Column | Type | Description |
|--------|------|-------------|
| `id` | VARCHAR(50) PK | Unique ID (`prop_{timestamp}`) |
| `agencyId` | VARCHAR(50) | Owning agency (FK to agencies.id) |
| `authorName` | VARCHAR(191) | Consultant who registered the property |
| `status` | VARCHAR(50) | `موجود` (available) or `واگذار شده` (transferred) |
| `referrer` | VARCHAR(191) | Property owner/agent name |
| `phone` / `phone2` | VARCHAR(20) | Contact numbers |
| `city` | VARCHAR(100) | City name |
| `location` | VARCHAR(191) | Neighborhood/district (public) |
| `exactAddress` | TEXT | Full address (confidential) |
| `lat` / `lng` | VARCHAR(50) | GPS coordinates for map |
| `usage_type` | VARCHAR(50) | Residential, Commercial, Office, Land, Garden, Villa |
| `area` | INT | Land area (m²) |
| `buildArea` | INT | Building area (m²) — for land/villa |
| `rooms` | VARCHAR(50) | Bedroom count (0-5+) |
| `floor` | VARCHAR(50) | Floor number |
| `unit` | VARCHAR(50) | Unit number |
| `yearBuilt` | VARCHAR(10) | Construction year (Shamsi) |
| `hasParking` | TINYINT(1) | Parking availability |
| `hasElevator` | TINYINT(1) | Elevator availability |
| `hasStorage` | TINYINT(1) | Storage room availability |
| `dealType` | VARCHAR(50) | Sale, Rent+Deposit, Full Deposit |
| `price` | DOUBLE | Total price (toman) |
| `deposit` | DOUBLE | Deposit/mortgage amount |
| `rent` | DOUBLE | Monthly rent |
| `description` | TEXT | Public description |
| `internalNote` | TEXT | Private note (staff only) |
| `canExchange` | TINYINT(1) | Exchange/trade possible |
| `canPartner` | TINYINT(1) | Partnership/construction possible |
| `isPreSale` | TINYINT(1) | Pre-sale/off-plan |
| `isVIP` | TINYINT(1) | VIP/featured listing |
| `showToGuest` | TINYINT(1) | Visible to guest users |
| `showPriceGuest` | TINYINT(1) | Price visible to guests |
| `showImagesGuest` | TINYINT(1) | Images visible to guests |
| `date` | DATE | Registration date |
| `soldBy` | VARCHAR(191) | Consultant who closed the deal |
| `images` | JSON | Array of image file paths |

### Table: `demands`

Client requirements — what buyers/renters are looking for.

| Column | Type | Description |
|--------|------|-------------|
| `id` | VARCHAR(50) PK | Unique ID (`dem_{timestamp}`) |
| `agencyId` | VARCHAR(50) | Owning agency |
| `authorName` | VARCHAR(191) | Consultant who registered the demand |
| `clientName` | VARCHAR(191) | Client name |
| `clientPhone` / `clientPhone2` | VARCHAR(20) | Client contact |
| `city` | VARCHAR(100) | Desired city |
| `usage_type` | VARCHAR(50) | Desired property type |
| `dealType` | VARCHAR(50) | Desired deal type |
| `area` | INT | Desired area |
| `budget` | DOUBLE | Budget (for purchase) |
| `deposit` | DOUBLE | Deposit budget |
| `rent` | DOUBLE | Rent budget |
| `description` | TEXT | Additional requirements |
| `followUpDate` | VARCHAR(50) | Follow-up date |
| `date` | DATE | Registration date |

### Table: `members`

Consultant accounts within each agency.

| Column | Type | Description |
|--------|------|-------------|
| `id` | VARCHAR(50) PK | `{agencyId}_{name}` composite key |
| `agencyId` | VARCHAR(50) | Parent agency |
| `name` | VARCHAR(191) | Consultant display name |
| `pin` | VARCHAR(100) | Login PIN |
| `status` | VARCHAR(20) | `active`, `pending`, or `blocked` |
| `lastSeen` | INT | Unix timestamp of last ping |
| `joinedAt` | DATETIME | Registration date |

### Table: `rate_limits`

API rate limiting.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT AUTO_INCREMENT PK | Row ID |
| `ip` | VARCHAR(64) | Composite rate limit key (SHA-256 hash) |
| `request_time` | INT | Unix timestamp of request |

### Table: `personal_notes`

Cloud-synced personal notes.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT AUTO_INCREMENT PK | Row ID |
| `agencyId` | VARCHAR(50) | Agency scope |
| `username` | VARCHAR(191) | Note owner |
| `note_text` | MEDIUMTEXT | Note content |
| `updatedAt` | TIMESTAMP | Last modification |
| UNIQUE KEY | `(agencyId, username)` | One note per user per agency |

### Table: `sys_config`

System configuration key-value store.

| Column | Type | Description |
|--------|------|-------------|
| `conf_key` | VARCHAR(64) PK | Configuration key |
| `conf_val` | VARCHAR(255) | Configuration value |

Known keys: `schema_version`, `last_update`

---

## API Reference

All requests go through a single endpoint: `api.php?action={action}`

### Authentication

Every authenticated request must include:

```
Headers:
  Content-Type: application/json
  X-Agency-ID: {6-digit code}
  X-Auth-Token: {HMAC token from login}
  X-API-Key: AmLaK_Super_Secret_2026!

Body (POST): { "api_key": "AmLaK_Super_Secret_2026!", ... }
```

### Actions

| Action | Method | Auth | Description |
|--------|--------|------|-------------|
| `getData` | POST | Token | Fetch all data for the authenticated agency + public listings |
| `loginManager` | POST | None | Manager login (agency code + PIN) |
| `loginConsultant` | POST | None | Consultant login (agency code + name + PIN) |
| `saveProperty` | POST | Token | Create or update a property listing |
| `saveDemand` | POST | Token | Create or update a client demand |
| `saveMember` | POST | Manager | Create or update a consultant account |
| `deleteProperty` | POST | Manager | Delete a property and its images |
| `deleteDemand` | POST | Manager | Delete a client demand |
| `deleteMember` | POST | Manager | Delete a consultant account |
| `saveAgency` | POST | Master | Create a new agency (from creator panel) |
| `changeMyPassword` | POST | Token | Change own password |
| `resetMemberPassword` | POST | Manager | Reset a consultant's password |
| `ping` | POST | Token | Heartbeat for online status tracking |
| `savePersonalNote` | POST | Token | Save personal notes to cloud |
| `getPersonalNote` | POST | Token | Retrieve personal notes from cloud |
| `stt` | POST (multipart) | Token+VIP | Upload audio for speech-to-text |
| `jarvisProcess` | POST | Token+VIP | Send text to LLM for field extraction |

### Response Format

```json
{
  "response": {
    "success": true,
    "id": "prop_1234567890"
  }
}
```

Error responses:
```json
{
  "error": "خطای داخلی سرور. لطفاً دوباره تلاش کنید."
}
```

### Rate Limiting

- **Limit:** 150 requests per minute per composite key
- **Key:** SHA-256(IP + AgencyID + AuthToken), truncated to 15 characters
- **Storage:** `rate_limits` table, cleaned on each request (entries older than 60s removed)

---

## Authentication Model

### Token Generation

```php
function generateSecureToken($agency, $role, $name, $salt, $plan) {
    $payload = base64_encode(json_encode([
        'a' => $agency,    // Agency ID
        'r' => $role,      // Role (مدیر / مشاور)
        'n' => $name,      // User name
        'p' => $plan,      // Plan (Basic / Pro / VIP)
        'exp' => time() + 108000  // Expiry: 30 hours
    ]));
    return $payload . '.' . hash_hmac('sha256', $payload, $salt);
}
```

### Token Verification

1. Split token on `.` → payload + signature
2. Recompute HMAC-SHA256 of payload with `APP_SALT`
3. Compare signatures using `hash_equals()` (constant-time)
4. Decode payload JSON → check `exp` > current time
5. Extract agency, role, name, plan from payload

### Login Flow

```
Consultant Login:
  1. Client sends: { agencyId, name, pin }
  2. Server finds member by agencyId + name
  3. If not found: create member with status='pending'
  4. If status='pending': return message "Awaiting manager approval"
  5. If status='blocked': return error "Access denied"
  6. Verify PIN with pin_matches() (bcrypt or plaintext)
  7. Generate token with agency plan
  8. Return: { success, token, agencyName, plan }
```

---

## Data Flow

### Property Registration

```
User fills form → compressImage() (client-side resize + watermark)
    → base64 encode images
    → apiCall('saveProperty', payload)
    → Server: validate auth, sanitize inputs
    → For each image: decode base64, verify pixel count < 20MP
    → GD resize to max 800px width, JPEG quality 70%
    → Save to uploads/prop_{agency}_{time}_{idx}_{uniqid}.jpg
    → INSERT INTO properties ... ON DUPLICATE KEY UPDATE ...
    → markSystemUpdated() → update sys_config.last_update
    → Return { success, id }
```

### Real-Time Sync

```
Every 15 seconds:
  → apiCall('getData') with X-Data-Hash header
  → Server checks sys_config.last_update
  → If hash matches: return { unmodified: true } (304-like)
  → If changed: return full dataset (agency properties + demands + members + public)
  → Client: processData() → diff check → re-render only changed sections
```

---

## Jarvis AI Pipeline

### Speech-to-Text (STT)

Three providers are supported, selected via `STT_PROVIDER` in `config.php`:

| Provider | Endpoint | Format | Notes |
|----------|----------|--------|-------|
| Hugging Face | `router.huggingface.co/hf-inference/models/{model}` | Raw bytes + Bearer token | Default; free tier available |
| Groq | `api.groq.com/openai/v1/audio/transcriptions` | Multipart (CURLFile) | Fast, free tier; recommended for Persian |
| OpenAI | `api.openai.com/v1/audio/transcriptions` | Multipart (CURLFile) | Highest quality; paid |

### Client-Side Audio Processing

```
Microphone → MediaRecorder (WebM/Opus)
    → Blob.arrayBuffer()
    → AudioContext.decodeAudioData()
    → OfflineAudioContext (resample to 16kHz mono)
    → encodeWavPcm16() (WAV PCM 16-bit)
    → FormData upload to api.php?action=stt
```

### NLU Processing (Server-Side)

```
Transcribed text → OpenRouter API (google/gemma-4-26b-a4b-it:free)
    → System prompt (jarvis-prompt30.txt) with field schema
    → LLM extracts structured JSON: {dealType, usage, city, price, ...}
    → Return to client
```

### Field Normalization (Client-Side)

The `JarvisFields.normalize()` function (`jarvis/fields30.js`) performs:

1. **Persian/Arabic numeral conversion** — `۰-۹` and `٠-٩` → `0-9`
2. **Unicode normalization** — NFKC + character folding (ي→ی, ك→ک)
3. **Compound number parsing** — "یک میلیارد و نیم" → 1,500,000,000
4. **Currency detection** — toman vs. rial with automatic conversion
5. **Enum matching** — Deal types and usage types with synonym dictionaries
6. **Phone normalization** — +98/0098/98 prefixes → 09xxxxxxxxx
7. **Boolean parsing** — "دارد"/"ندارد"/"بله"/"خیر" → true/false
8. **Cross-field validation** — Detect conflicts (e.g., sale + rent amounts)
9. **Warning generation** — Flag ambiguous values without guessing

---

## Frontend Architecture

### Single-Page Application (SPA)

The entire frontend lives in a single `index.html` file (4,688 lines):

```
Lines 1-130:     Recovery boot loader (diagnostic panel)
Lines 130-600:   CSS styles (landing page + panel + responsive)
Lines 600-950:   HTML structure (landing + auth + app shell)
Lines 950-1400:  Form templates (property + demand + members)
Lines 1400-1800: Card rendering + gallery + print
Lines 1800-2200: API layer + mock DB + data processing
Lines 2200-2600: Authentication + session management
Lines 2600-3200: Rendering (properties, demands, members, dashboard)
Lines 3200-3700: Map integration (Neshan SDK)
Lines 3700-4000: Plan lock engine + personal notes + ping service
Lines 4000-4200: Voice recording + WAV encoding
Lines 4200-4500: Jarvis field parser (fields30.js inlined)
Lines 4500-4688: Jarvis bridge (bridge30.js inlined) + boot
```

### View Management

Six primary views, toggled via `switchTab()`:

| View ID | Content | Access |
|---------|---------|--------|
| `list-view` | Property cards with filters | Consultant, Manager |
| `demands-view` | Client demands with matching | Consultant, Manager |
| `add-view` | Property form (add/edit) | Consultant, Manager |
| `map-view` | Interactive Neshan map | Pro, VIP |
| `profile-view` | User profile + settings | All authenticated |
| `dashboard-view` | Analytics charts | VIP only |

Guest-specific views:
| View ID | Content |
|---------|---------|
| `guest-search-view` | Cross-agency property search |
| `guest-agencies-view` | Agency directory |
| `guest-properties-view` | Selected agency's public listings |

### State Management

Application state is held in module-scoped variables:

```javascript
let properties = [];        // Current agency's property listings
let demands = [];           // Current agency's client demands
let membersList = [];       // Current agency's consultants
let allAgencies = [];       // All agencies (for guest search)
let allGuestProperties = []; // Public property listings
let userProfile = null;     // Current user session
let lastSyncHash = '';      // Data change detection hash
let tempImagesBase64 = [];  // Pending image uploads (max 3)
let editingId = null;       // Property ID being edited
```

Session persistence:
- `localStorage.amlakProfile` — User session (survives browser restart)
- `sessionStorage.guestProfile` — Guest session (cleared on tab close)
- `localStorage.amlakDataCache` — Last fetched data (for offline display)
- `localStorage.amlakDarkMode` — Theme preference

---

## PWA & Service Worker

### Manifest

```json
{
  "name": "سیستم مدیریت فایلینگ املاک",
  "short_name": "املاک من",
  "display": "standalone",
  "orientation": "portrait",
  "theme_color": "#1e40af"
}
```

### Service Worker Strategy

The service worker (`sw.js`) uses a **network-first** strategy:

1. All `api.php` requests bypass the service worker entirely
2. Navigation requests use `fetch()` with `cache: 'no-store'`
3. On network failure, navigation requests return a 503 page in Persian
4. Non-navigation requests return `Response.error()`
5. No application data is cached (prevents stale data issues)

---

## Security Architecture

### Defense in Depth

```
Layer 1: Apache (.htaccess)
  ├── Block config.php, .htaccess, .htpasswd access
  ├── Block script execution in uploads/
  ├── Block direct access to backups/, tools/, cgi-bin/
  └── Security headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy)

Layer 2: PHP Application
  ├── API key validation (all POST requests)
  ├── HMAC token verification (authenticated requests)
  ├── Role-based access control (Manager-only operations)
  ├── Rate limiting (150 req/min per composite key)
  ├── Input sanitization (htmlspecialchars + strip_tags)
  └── PDO prepared statements (zero SQL injection surface)

Layer 3: Data
  ├── Agency-scoped queries (all SELECT/UPDATE/DELETE filtered by agencyId)
  ├── Path traversal prevention (safeUploadRelPath + realpath verification)
  ├── Image validation (pixel count cap, dimension check, format verification)
  └── Error message sanitization (no PDOException details to client)

Layer 4: Transport
  ├── CORS with configurable origin whitelist
  ├── Content Security Policy headers
  └── SSL/TLS enforced (by hosting provider)
```

---

## Deployment Topology

### Production Environment

```
Internet
    ↓
Cloudflare CDN (optional)
    ↓
Apache 2.4 (DirectAdmin shared hosting)
    ├── public_html/
    │   ├── index.html        (SPA frontend)
    │   ├── api.php           (REST API)
    │   ├── stt.php           (STT engine)
    │   ├── config.php        (credentials, not in git)
    │   ├── .htaccess         (security rules)
    │   ├── sw.js             (service worker)
    │   ├── manifest.json     (PWA manifest)
    │   ├── assets/           (fonts, libraries)
    │   ├── uploads/          (user images, .htaccess protected)
    │   └── backups/          (cron-generated JSON backups)
    └── MySQL 5.7
        └── owihpfoa_test_db (7 tables)
```

### Backup Strategy

- **Automated:** `backup.php` via cron (daily at 3 AM)
- **Tables backed up:** agencies, properties, demands, members, personal_notes
- **Format:** JSON with timestamps
- **Retention:** 7 days (older files auto-deleted)
- **Atomic writes:** Write to `.tmp` file, then `rename()` to prevent partial backups

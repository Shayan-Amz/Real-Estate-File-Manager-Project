# Complete Feature Inventory

> An exhaustive list of every feature, capability, and UI element in the Amlak-e-Man platform.

---

## Table of Contents

- [1. Landing Page](#1-landing-page)
- [2. Authentication System](#2-authentication-system)
- [3. Property Management](#3-property-management)
- [4. Demand Management](#4-demand-management)
- [5. Map Integration](#5-map-integration)
- [6. Jarvis AI Assistant](#6-jarvis-ai-assistant)
- [7. Analytics Dashboard](#7-analytics-dashboard)
- [8. Team Management](#8-team-management)
- [9. Guest Mode (Public Access)](#9-guest-mode-public-access)
- [10. Profile & Settings](#10-profile--settings)
- [11. Image Processing Pipeline](#11-image-processing-pipeline)
- [12. Search & Filtering](#12-search--filtering)
- [13. Data Synchronization](#13-data-synchronization)
- [14. Subscription & Feature Gating](#14-subscription--feature-gating)
- [15. Super Admin Panel](#15-super-admin-panel)
- [16. Agency Creator](#16-agency-creator)
- [17. Backup System](#17-backup-system)
- [18. PWA & Offline Support](#18-pwa--offline-support)
- [19. Accessibility & Internationalization](#19-accessibility--internationalization)
- [20. Security Features](#20-security-features)
- [21. Developer & Diagnostic Tools](#21-developer--diagnostic-tools)
- [22. Academic Evaluation Framework](#22-academic-evaluation-framework)

---

## 1. Landing Page

| Feature | Description |
|---------|-------------|
| Hero Section | Full-viewport gradient background with animated mockup, floating badges, and CTA buttons |
| Glass-Morphism Navigation | Sticky nav bar with backdrop blur and transparency |
| Feature Grid | Responsive grid of feature cards with hover animations |
| Contact Section | Dark-themed section with phone number card |
| Blob Backgrounds | Animated CSS blur blobs for visual depth |
| Responsive Layout | Adapts from single-column (mobile) to side-by-side (desktop) |
| Smooth Scroll | "Get License" button scrolls to contact section |
| Logo with Fallback | Image logo with emoji fallback on load failure |

---

## 2. Authentication System

### Login Tabs

| Tab | Fields | Behavior |
|-----|--------|----------|
| **Guest** | None (click to enter) | Session-only profile, cleared on tab close |
| **Consultant** | Agency code (6 digits), Name, PIN | Creates pending member if new; requires manager approval |
| **Manager** | Agency code (6 digits), PIN | Full agency access; verifies against `adminPin` |

### Features

- **Loading states** — Submit buttons disable and show spinner during authentication
- **Error messages** — Toast notifications for wrong PIN, expired subscription, blocked accounts
- **Session persistence** — `localStorage` for consultants/managers, `sessionStorage` for guests
- **Subscription check** — Validates `expireAt` date before granting access
- **Plan detection** — Reads `plan_type` from server and includes it in the auth token
- **Auto-redirect** — Returns to app if already logged in (from `localStorage`)

---

## 3. Property Management

### Property Form (25+ Fields)

| Field Group | Fields |
|-------------|--------|
| **Contact** | Referrer/Owner name, Primary phone, Secondary phone |
| **Location** | City, Neighborhood, Exact address, GPS coordinates (via map picker) |
| **Specifications** | Area (m²), Building area, Usage type (6 options), Rooms (0-5+), Floor, Unit, Year built |
| **Amenities** | Parking ✓, Elevator ✓, Storage ✓ |
| **Deal Type** | Sale / Rent+Deposit / Full Deposit (radio buttons) |
| **Financial** | Total price, Deposit amount, Monthly rent |
| **Options** | Exchange ✓, Partnership ✓, Pre-sale ✓, VIP ✓ |
| **Visibility** | Show to guests ✓, Show price to guests ✓, Show images to guests ✓ |
| **Notes** | Public description, Internal note (staff only) |
| **Images** | Up to 3 photos with preview, remove, and re-order |

### Smart Form Behavior

- **Land/villa mode** — Shows building area field when usage is "Land" or "Villa"
- **Residential mode** — Shows floor/unit fields only for residential/commercial/office
- **Rent fields** — Dynamically shown/hidden based on deal type selection
- **Price formatting** — Automatic thousand-separator insertion while typing
- **Input validation** — Regex-based digit filtering, max length enforcement
- **Phone formatting** — LTR direction, 11-digit max, digit-only enforcement

### Property Cards

- **Status badges** — "Available" (green) or "Transferred" (red overlay)
- **VIP indicator** — Gold star badge for featured listings
- **Deal type tags** — Color-coded labels (Sale=green, Rent=blue, Full Deposit=purple)
- **Metadata chips** — Area, rooms, year, floor, parking/elevator/storage icons
- **Price section** — Dashed-border box with price/deposit/rent lines
- **Image thumbnails** — Horizontal scrollable gallery with click-to-zoom
- **Action buttons** — Edit, Delete (manager only), Copy to clipboard, Print catalog
- **Demand matching** — "View matching demands" button (VIP feature)

### Property Lifecycle

- **Create** — Fill form + upload images → save with auto-generated ID
- **Edit** — Click edit button → form pre-filled with existing data
- **Delete** — Manager-only confirmation → removes property + deletes image files
- **Status change** — Mark as "Transferred" with consultant who closed the deal

---

## 4. Demand Management

### Demand Form

| Field | Description |
|-------|-------------|
| Client name | Full name of the buyer/renter |
| Client phone (primary) | Main contact number |
| Client phone (secondary) | Backup contact |
| City | Desired location |
| Usage type | Residential, Commercial, Office, Land, Garden, Villa |
| Deal type | Purchase, Rent+Deposit, Full Deposit |
| Area | Desired area (m²) |
| Budget | Purchase budget (for sale deals) |
| Deposit | Deposit budget (for rental deals) |
| Rent | Monthly rent budget |
| Description | Additional requirements |
| Follow-up date | Jalali calendar date picker |

### Demand-Property Matching

The system automatically identifies properties that match a demand based on:
- Same city
- Same usage type
- Same deal type
- Area within ±20% of requested area
- Price/budget compatibility

---

## 5. Map Integration

### Map Picker Modal (Property Registration)

- **Neshan Maps SDK** — Iranian map tiles with Persian labels
- **Interactive pin** — Draggable marker for precise location
- **Click-to-place** — Click anywhere on map to move the pin
- **Coordinate capture** — Latitude/longitude stored in hidden form fields
- **Default center** — Tehran (35.6892, 51.3890) at zoom level 12
- **Lazy loading** — Map SDK loaded on demand (not on page load)
- **Error resilience** — Map failure doesn't block property registration

### Full Map View

- **Property markers** — All geotagged properties shown as map markers
- **Marker popups** — Click marker to see property summary
- **Property count** — Live count of mapped properties
- **Full-height layout** — Map fills available viewport height

---

## 6. Jarvis AI Assistant

### Voice Input

| Feature | Detail |
|---------|--------|
| Recording | MediaRecorder API with WebM/Opus encoding |
| Max duration | 60 seconds (auto-stop) |
| WAV conversion | Client-side resampling to 16kHz mono PCM |
| Visual feedback | Pulsing red recording indicator |
| File upload fallback | Upload pre-recorded audio file |

### Text Input

| Feature | Detail |
|---------|--------|
| Chat interface | Floating button → expandable modal with message history |
| Real-time typing | Send button and Enter key support |
| AI response | Streaming-style "typing..." indicator followed by structured response |

### Field Extraction Engine (JarvisFields)

| Capability | Examples |
|------------|---------|
| Persian numerals | `۱۲۰` → 120, `٥` → 5 |
| Compound numbers | `یک میلیارد و نیم` → 1,500,000,000 |
| Currency units | `تومان`, `تومن`, `ریال` with auto-conversion |
| Ordinal floors | `طبقه سوم` → floor 3, `همکف` → floor 0 |
| Phone normalization | `+989121234567` → `09121234567` |
| Usage synonyms | `آپارتمان`, `خانه`, `منزل` → `مسکونی` |
| Deal synonyms | `خرید`, `sale` → `فروش` |
| Boolean parsing | `دارد`/`ندارد`, `بله`/`خیر` |
| Price-per-meter | Auto-calculates total price from per-meter × area |
| Conflict detection | Sale + rent amounts → warning |

### Draft Management

- **Empty form** → Draft applied directly
- **Populated form** → "Apply draft" button shown in chat (requires confirmation)
- **Editing existing property** → Never auto-overwrites
- **Command sequencing** → Late responses from previous commands are discarded
- **Account binding** → Drafts are bound to the current user session

---

## 7. Analytics Dashboard (VIP Feature)

| Chart | Description |
|-------|-------------|
| **Registrations by Consultant** | Bar chart showing how many properties each consultant registered |
| **Sales by Consultant** | Bar chart showing closed deals per consultant |
| **Summary Statistics** | Total properties, available, transferred, demands, active consultants |

- Powered by **Chart.js 4.x**
- Responsive canvas with Persian axis labels
- Auto-refreshes when data changes

---

## 8. Team Management (Manager Only)

### Consultant Lifecycle

```
Consultant registers → Status: pending → Manager approves → Status: active
                                                    ↓
                                              Manager blocks → Status: blocked
                                                    ↓
                                              Manager unblocks → Status: active
                                                    ↓
                                              Manager deletes → Removed from system
```

### Features

| Feature | Description |
|---------|-------------|
| **Approve** | Activate a pending consultant account |
| **Block** | Prevent a consultant from logging in (auto-logout on next sync) |
| **Unblock** | Restore access for a blocked consultant |
| **Delete** | Permanently remove a consultant (with confirmation) |
| **Reset Password** | Set a new PIN for a consultant |
| **Online Status** | Green/gray indicator based on 20-second heartbeat pings |
| **Active Members List** | Shown in agency stats bar at top of page |

---

## 9. Guest Mode (Public Access)

### Cross-Agency Search

- Search all public properties across every agency
- Same filter set as authenticated users (usage, deal type, price, area)
- Properties only shown if `showToGuest = true` and `status = 'موجود'`

### Agency Directory

- Browse all registered agencies
- Search by agency name or city
- Click to view an agency's public listings

### Agency-Specific Listings

- View public properties of a selected agency
- Agency name shown in header
- Back button to return to directory

### Restrictions

- No property creation, editing, or deletion
- No access to demands, members, or dashboard
- No internal notes visible
- Price/images may be hidden per property (`showPriceGuest`, `showImagesGuest`)
- Session stored in `sessionStorage` (cleared on tab close)

---

## 10. Profile & Settings

| Feature | Description |
|---------|-------------|
| **Profile Display** | Avatar emoji, user name, role badge |
| **Manager Tools** | Quick links to Dashboard and Members (manager only) |
| **Change Password** | Prompt for new PIN with minimum length check |
| **Logout** | Clear local storage and reload |
| **Personal Notes** | Cloud-synced notepad (one per user per agency) |

---

## 11. Image Processing Pipeline

```
User selects file(s)
    ↓
Size check (max 10MB per file)
    ↓
FileReader → Base64 data URL
    ↓
Image() object → Canvas draw
    ↓
Resize: max 800px width (proportional height)
    ↓
Watermark: Agency name at 45° angle, semi-transparent
    ↓
JPEG compression (quality 70%)
    ↓
Base64 stored in tempImagesBase64[]
    ↓
Preview thumbnails with remove buttons
    ↓
On form submit → sent as base64 in JSON
    ↓
Server: base64_decode → getimagesizefromstring (pixel count check)
    ↓
Server: imagecreatefromstring → resize to 800px → imagejpeg (quality 70%)
    ↓
Saved as: uploads/prop_{agencyId}_{timestamp}_{index}_{uniqid}.jpg
```

### Security Checks

- **SVG/XML rejection** — Prevents XML-based attacks via images
- **Base64 size cap** — Max characters per image string
- **Pixel count limit** — 20 megapixels maximum (prevents decompression bombs)
- **Path validation** — Only `uploads/` paths with image extensions accepted
- **Extension whitelist** — jpg, jpeg, png, webp, gif only

---

## 12. Search & Filtering

### Text Search

- **Scope:** City, location, address, referrer, phone, phone2, description, internal note, author, usage, deal type, rooms, year built
- **Normalization:** Persian/Arabic character folding, zero-width joiner removal
- **Real-time:** Filters on every keystroke (`oninput`)

### Filter Controls (12 Filters)

| Filter | Type | Options |
|--------|------|---------|
| Usage | Dropdown | Residential, Commercial, Office, Land, Garden, Villa |
| Deal Type | Dropdown | Sale, Rent+Deposit, Full Deposit |
| Min Price | Text (numeric) | Auto-formatted with thousand separators |
| Max Price | Text (numeric) | Auto-formatted |
| Min Rent | Text (numeric) | Shown only when deal type = Rent |
| Max Rent | Text (numeric) | Shown only when deal type = Rent |
| Min Area | Text (numeric) | Digit-only enforcement |
| Max Area | Text (numeric) | Digit-only enforcement |
| Status | Dropdown | All, Available only, Transferred only |
| Exchange | Checkbox | Only properties allowing exchange |
| Partnership | Checkbox | Only properties allowing partnership |
| Pre-sale | Checkbox | Only pre-sale/off-plan properties |

### Sort Options

| Option | Direction |
|--------|-----------|
| Registration date | Newest first (default) |
| Price | Ascending / Descending |
| Area | Ascending / Descending |

### View Modes

| Mode | Layout |
|------|--------|
| **Full** | Rich cards with images, metadata, prices, description |
| **Compact** | Condensed list with key details and right-border accent |

### Pagination

- Configurable items per page (`ITEMS_PER_PAGE`)
- Previous/Next buttons with page counter
- Auto-adjusts when filter changes reset to page 1

### Clear All Filters

- Single button to reset all filter controls and sort to default

---

## 13. Data Synchronization

### Auto-Sync

| Parameter | Value |
|-----------|-------|
| Interval | 15 seconds |
| Method | POST `getData` with `X-Data-Hash` header |
| Change detection | Server compares `sys_config.last_update` with client hash |
| Unchanged response | `{ unmodified: true }` (minimal bandwidth) |
| Changed response | Full dataset (all agency data + public listings) |

### Visibility Change Handling

- **Tab hidden** → Sync interval cleared (saves battery/bandwidth)
- **Tab visible** → Immediate fetch + restart interval

### Offline Detection

- `window.addEventListener('offline')` → Toast notification + flag
- `window.addEventListener('online')` → Toast + immediate fetch

### Heartbeat Ping

- Every 20 seconds, authenticated users send a `ping` action
- Server updates `members.lastSeen` with current Unix timestamp
- UI checks `lastSeen` within 120-second window for "online" status

---

## 14. Subscription & Feature Gating

### Plan Definitions

| Feature | Basic | Pro | VIP |
|---------|-------|-----|-----|
| Property CRUD | ✅ | ✅ | ✅ |
| Demands | ✅ | ✅ | ✅ |
| Image Upload | ✅ | ✅ | ✅ |
| Guest Visibility Controls | ✅ | ✅ | ✅ |
| Personal Notes | ✅ | ✅ | ✅ |
| Password Change | ✅ | ✅ | ✅ |
| Interactive Map | 🔒 | ✅ | ✅ |
| CSV Export | 🔒 | ✅ | ✅ |
| Print Catalog | 🔒 | ✅ | ✅ |
| Demand-Property Matching | 🔒 | ✅ | ✅ |
| Analytics Dashboard | 🔒 | 🔒 | ✅ |
| Jarvis AI Assistant | 🔒 | 🔒 | ✅ |

### Lock Engine

- **Scan interval:** 500ms continuous DOM scanning
- **Detection method:** Matches elements by `onclick` attribute content and element ID
- **Visual lock:** Grayscale filter + dashed red border + 🔒 overlay badge
- **Click interception:** `capture: true` event listener prevents all clicks on locked elements
- **Jarvis special handling:** Custom lock badge (not generic `.locked-item` class) to avoid conflicts
- **Cannot be bypassed:** Even DOM manipulation is caught by the next scan cycle

---

## 15. Super Admin Panel

**File:** `super_admin_license_x_382967.php`

### Features

| Feature | Description |
|---------|-------------|
| **Master Password Login** | Verifies against `MASTER_PASSWORD_HASH` with 1-second delay |
| **Agency List** | Table of all agencies with code, name, manager, property count, expiry |
| **Subscription Extension** | +1 month, +6 months, +1 year buttons per agency |
| **Plan Management** | Change agency plan (Basic / Pro / VIP) with colored badges |
| **Password Reset** | Change an agency's admin PIN (POST method for security) |
| **Agency Deletion** | Remove agency + all its properties, demands, and members |
| **Jalali Dates** | Expiry dates shown in Persian Shamsi calendar format |
| **CSRF Protection** | Random tokens on all state-changing operations |
| **Session Management** | PHP sessions with regeneration and flash messages |

---

## 16. Agency Creator

**File:** `amlak_creator_b_1687.php`

### Workflow

1. Enter master password
2. Fill in agency details (name, city, phones, manager name, admin PIN)
3. Set subscription duration (days)
4. Click "Generate Agency Code"
5. 6-digit code is randomly generated and registered in the database

### Fields

| Field | Required | Description |
|-------|----------|-------------|
| Master Password | ✅ | System-wide master password |
| Agency Name | ✅ | Display name |
| City | ✅ | Agency location |
| Primary Phone | ✅ | Contact number |
| Secondary Phone | ❌ | Backup contact |
| Manager Name | ✅ | Default manager name |
| Admin PIN | ✅ | Manager login password |
| Validity Days | ✅ | Subscription duration (default: 30) |

---

## 17. Backup System

**File:** `backup.php`

### Features

| Feature | Detail |
|---------|--------|
| **Trigger** | Cron job (`0 3 * * *`) or manual CLI |
| **Scope** | All 5 data tables (agencies, properties, demands, members, personal_notes) |
| **Format** | JSON with timestamp and per-table row counts |
| **Storage** | `backups/backup_{date}_{time}.json` |
| **Retention** | Files older than 7 days auto-deleted |
| **Atomic writes** | Write to `.tmp`, then `rename()` |
| **Permissions** | `chmod 0640` on backup files |
| **Browser block** | HTTP 403 if accessed from browser |
| **Error logging** | Errors go to `error_log` + stdout |

---

## 18. PWA & Offline Support

### Installability

- **Web App Manifest** — Name, icons (192×192, 512×512), theme color, standalone display
- **Apple Meta Tags** — `apple-mobile-web-app-capable`, status bar style, touch icon

### Service Worker

- **Install** — `skipWaiting()` for immediate activation
- **Activate** — Claims all clients; cleans obsolete caches
- **Fetch strategy** — Network-first with 503 fallback page
- **API bypass** — `api.php` requests never cached
- **No data caching** — Only shell resources; user data is never stale

---

## 19. Accessibility & Internationalization

### Persian Language Support

| Feature | Implementation |
|---------|---------------|
| **RTL Layout** | `dir="rtl"` on `<html>`, all CSS designed right-to-left |
| **Vazirmatn Font** | Local woff2 with `font-display: swap` |
| **Jalali Calendar** | Jalali Date Picker library for all date inputs |
| **Persian Numerals** | Number conversion in Jarvis field parser |
| **Shamsi Dates** | All displayed dates in Persian calendar format |

### Responsive Design

| Breakpoint | Layout |
|------------|--------|
| < 640px | Single column, bottom tab navigation |
| 640–768px | Two-column form grids, responsive card grid |
| > 768px | Sidebar navigation (sticky), wider content area |

### Dark Mode

- **Toggle** — 🌙/☀️ button in header
- **Implementation** — CSS custom properties on `.dark` class
- **Persistence** — `localStorage.amlakDarkMode`
- **Coverage** — All UI elements (cards, forms, modals, navigation)

---

## 20. Security Features

### Complete Security Checklist

- [x] HMAC-SHA256 token authentication with 30-hour expiry
- [x] bcrypt password hashing for master password (pre-computed, not per-request)
- [x] Constant-time string comparison (`hash_equals()`) for credentials
- [x] PDO prepared statements exclusively (zero SQL injection surface)
- [x] Input sanitization (`htmlspecialchars` + `strip_tags`)
- [x] Client-side HTML escaping on all rendered content
- [x] CORS with configurable origin whitelist
- [x] Content Security Policy headers
- [x] Rate limiting (150 req/min per composite key)
- [x] CSRF tokens on admin panel operations
- [x] Session ID regeneration on login
- [x] Path traversal prevention (`safeUploadRelPath` + `realpath`)
- [x] Image decompression bomb prevention (pixel count cap)
- [x] SVG/XML upload rejection
- [x] PHP execution blocked in uploads directory
- [x] Config file protected from direct access
- [x] Backup directory protected from direct access
- [x] Error messages sanitized (no database details leaked)
- [x] Security headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy)
- [x] Proxy header trust configurable (not blindly accepted)
- [x] Atomic backup writes (no partial files)
- [x] Browser access blocked for sensitive CLI scripts

---

## 21. Developer & Diagnostic Tools

### Health Check (`tools/health_check.php`)

Verifies deployment integrity:
- Database connectivity and table existence
- Schema version matches code expectations
- Required columns exist on all tables
- Config values are properly set (not defaults)
- Upload directory is writable
- `.htaccess` files present and correct
- PHP extensions loaded (PDO, curl, GD, openssl)

### STT Probe (`tools/stt_probe.php`)

Tests speech-to-text pipeline:
- PHP version and extension check
- curl and openssl availability
- `upload_max_filesize` and `post_max_size` values
- Actual API call to each STT provider (HF, Groq, OpenAI)
- Response parsing and error diagnosis

### Recovery Boot (`index.html` lines 11–127)

Diagnostic panel embedded in the app:
- Logs resource loading failures
- Reports API response status codes
- Verifies backend version via `X-Amlak-Recovery` header
- Falls back to in-memory storage if `localStorage` is unavailable
- Copy-to-clipboard for bug reports (with credential redaction)

---

## 22. Academic Evaluation Framework

### Purpose

A standalone evaluation harness for measuring the Jarvis AI assistant's accuracy in extracting structured property data from natural language input.

### Components

| File | Purpose |
|------|---------|
| `index.html` | Evaluation interface with case selection and mode picker |
| `app.js` | Test runner with voice recording, text input, and manual modes |
| `metrics.js` | Scoring engine (field accuracy, error counting, timing) |
| `cases.json` | 5 predefined test scenarios with gold-standard values |
| `api.php` | Evaluation-specific API endpoint (STT + LLM proxy) |

### Test Scenarios

| Case | Type | Deal | Key Fields |
|------|------|------|------------|
| C01 | Residential apartment | Sale | 120m², 2 bedrooms, floor 3, 5B toman |
| C02 | Residential apartment | Rent+Deposit | 80m², 1 bedroom, 300M deposit, 12M rent |
| C03 | Commercial shop | Full Deposit | 50m², 1B deposit |
| C04 | Villa | Sale | 220m² land, 150m² building, 3 bedrooms, 8B |
| C05 | Office | Sale | 65m², floor 4, unit 10, 6B |

### Metrics

| Metric | Description |
|--------|-------------|
| **Field Accuracy** | Percentage of 16 fields matching gold standard |
| **Word Error Rate** | Character-level edit distance on transcription |
| **ASR Latency** | Time from audio upload to transcription result |
| **LLM Latency** | Time from text submission to structured output |
| **End-to-End Time** | Total time from input start to form population |

### Modes

- **Voice** — Record via microphone or upload audio file
- **Text** — Type the property description
- **Manual** — Fill the form by hand (baseline comparison)

---

<div align="center">

**Total Features Documented: 200+**

[← Back to README](../README.md) • [Architecture Reference](ARCHITECTURE.md)

</div>

<div align="center">

# 🏢 Amlak-e-Man — Intelligent Real Estate Management Platform

### A Full-Stack, Multi-Tenant, AI-Powered Property Filing System

**Built as a university capstone project — production-deployed since 2025**

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](src/api.php)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](src/setup_db.php)
[![JavaScript](https://img.shields.io/badge/Vanilla%20JS-ES6%2B-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)](src/index.html)
[![PWA](https://img.shields.io/badge/PWA-Ready-5A0FC8?style=for-the-badge&logo=pwa&logoColor=white)](src/manifest.json)
[![License](https://img.shields.io/badge/License-Proprietary-red?style=for-the-badge)](LICENSE)

[Key Features](#-key-features) • [Architecture](#️-architecture-overview) • [Getting Started](#-getting-started) • [Documentation](#-documentation)

</div>

---

## 📋 Table of Contents

- [About the Project](#-about-the-project)
- [Key Features](#-key-features)
- [Architecture Overview](#️-architecture-overview)
- [Technology Stack](#-technology-stack)
- [AI-Powered Jarvis Assistant](#-ai-powered-jarvis-assistant)
- [Multi-Tenant & Subscription System](#-multi-tenant--subscription-system)
- [Security](#-security)
- [Getting Started](#-getting-started)
- [Project Structure](#-project-structure)
- [Documentation](#-documentation)
- [Academic Context](#-academic-context)
- [Screenshots & UI](#-screenshots--ui)
- [Testing](#-testing)
- [Deployment](#-deployment)
- [Acknowledgments](#-acknowledgments)

---

## 🌟 About the Project

**Amlak-e-Man** (Persian: *املاک من* — "My Real Estate") is an enterprise-grade, cloud-based real estate management platform purpose-built for the Iranian property market. It serves as a comprehensive filing system for real estate agencies — managing property listings, client demands, consultant teams, and business analytics through a single, unified interface.

The platform was conceived, designed, and developed as a **university capstone/thesis project** at the University of Semnan, under the supervision of Dr. Dorrigiv. Despite its academic origin, the system is **fully production-deployed** and actively serving multiple real estate agencies across Iran.

### What Makes This Project Stand Out

| Dimension | Detail |
|-----------|--------|
| **Scale** | 8,300+ lines of hand-written source code across 15+ source files |
| **Full-Stack** | Complete frontend SPA, REST API, database design, server administration |
| **AI Integration** | Speech-to-text (Whisper) + LLM-powered natural language property entry |
| **Multi-Tenancy** | Isolated per-agency data with subscription-based feature gating |
| **Production-Ready** | Deployed on shared hosting, serving real users since 2025 |
| **Security** | 18+ security hardening passes — CORS, CSP, rate limiting, CSRF, HMAC tokens |
| **PWA** | Offline-capable Progressive Web App with service worker |
| **RTL & Persian** | Native right-to-left UI with Jalali calendar, Persian number parsing |
| **Academic Rigor** | Backed by a full LaTeX thesis, evaluation framework, and defense materials |

---

## 🚀 Key Features

### Core Property Management

- 🏠 **Property Listings** — Full CRUD for residential, commercial, office, land, garden, and villa properties
- 📝 **Client Demands** — Track what clients are looking for, with automatic matching against available properties
- 📸 **Image Gallery** — Upload up to 3 photos per property with automatic compression, resizing (max 800px), and **watermarking** (agency name overlay)
- 🗺️ **Interactive Map** — Neshan Maps SDK (Leaflet-based) with draggable pin for GPS coordinate capture
- 📊 **Analytics Dashboard** — Chart.js-powered bar charts showing property registrations and sales by consultant
- 👥 **Team Management** — Manager can approve, block, reset passwords, and monitor online status of consultants

### Intelligent Jarvis Assistant 🤖

- 🎙️ **Voice-to-Form** — Record property details via microphone; speech is transcribed by Whisper AI (via Hugging Face / Groq / OpenAI) and parsed into structured form fields
- 💬 **Natural Language Entry** — Type descriptions like *"120m² apartment in Semnan, Golestan district, for sale, 5 billion tomans, 2 bedrooms, no parking"* and the AI fills the entire form
- 🧮 **Persian Number Parsing** — Custom NLP engine handles Persian/Arabic numerals, compound numbers (e.g., *"یک میلیارد و نیم"* = 1.5 billion), currency units (toman/rial), and ordinal floors
- ⚠️ **Smart Validation** — Ambiguous or conflicting values are flagged with warnings, not silently guessed

### Multi-Tenant & Subscription Platform

- 🏢 **Multi-Agency Architecture** — Each agency operates in complete data isolation with a unique 6-digit code
- 💎 **Three Subscription Tiers** — Basic, Professional, and VIP with feature-gated access (map, export, dashboard, AI assistant)
- 🔐 **Role-Based Access Control** — Manager, Consultant, and Guest roles with granular permissions
- 📋 **License Management** — Super Admin panel for creating agencies, extending subscriptions, and managing plans
- ⏰ **Subscription Expiry** — Automatic enforcement of subscription periods with Jalali date display

### Advanced Search & Filtering

- 🔍 **Full-Text Search** — Across all property fields (address, owner, phone, description, features)
- 🏷️ **12+ Filter Criteria** — Usage type, deal type (sale/rent/full-deposit), price range, area range, rooms, status, exchange/partnership/presale flags
- 📄 **Pagination** — Client-side paging with configurable items per page
- 🗂️ **Dual View Modes** — Full card view and compact list view
- 🌍 **Guest Mode** — Public-facing property search across all agencies (with configurable visibility per property)

### Data & Export

- 📥 **CSV Export** — Download property listings as Excel-compatible CSV
- 🖨️ **Print Catalog** — Generate printable property catalogs (single or multi-select)
- 📋 **Clipboard Copy** — One-click copy of property details for messaging apps
- 💾 **Auto-Sync** — Real-time data synchronization every 15 seconds with hash-based change detection
- 📝 **Personal Notes** — Cloud-synced personal notebook per user

### UI/UX Excellence

- 🌙 **Dark Mode** — Full dark theme with CSS custom properties and localStorage persistence
- 📱 **Responsive Design** — Mobile-first layout, tested on phones, tablets, and desktops
- 🎨 **Modern Glass-Morphism** — Landing page with backdrop blur, gradient blobs, and floating badges
- 🔤 **Vazirmatn Font** — Local-loaded Persian webfont (no external CDN dependency)
- 📅 **Jalali Date Picker** — Persian calendar integration for all date inputs

---

## 🏗️ Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                        CLIENT LAYER                         │
│  ┌───────────────────────────────────────────────────────┐  │
│  │   index.html (SPA) — 4,688 lines                     │  │
│  │  ┌─────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ │  │
│  │  │ Landing  │ │   Auth   │ │   Panel  │ │  Jarvis  │ │  │
│  │  │  Page    │ │  System  │ │  Views   │ │    AI    │ │  │
│  │  └─────────┘ └──────────┘ └──────────┘ └──────────┘ │  │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌────────┐  │  │
│  │  │ Neshan   │ │ Chart.js │ │ Jalali   │ │  SW /  │  │  │
│  │  │ Maps     │ │ Dashboard│ │ Calendar │ │  PWA   │  │  │
│  │  └──────────┘ └──────────┘ └──────────┘ └────────┘  │  │
│  └───────────────────────────────────────────────────────┘  │
│                         ↕ HTTPS / JSON                       │
├─────────────────────────────────────────────────────────────┤
│                        SERVER LAYER                         │
│  ┌─────────────┐  ┌──────────┐  ┌──────────────────────┐  │
│  │  api.php     │  │  stt.php  │  │  super_admin.php    │  │
│  │  (1,103 L)  │  │  (293 L)  │  │  (License Panel)    │  │
│  │  REST API   │  │  STT Engine│  │  amlak_creator.php  │  │
│  └──────┬──────┘  └─────┬────┘  └──────────────────────┘  │
│         │               │                                   │
│  ┌──────┴──────────────┴──────────────────────────────┐    │
│  │  External AI Services                               │    │
│  │  • Hugging Face Router (Whisper STT)               │    │
│  │  • Groq API (Whisper-large-v3-turbo)               │    │
│  │  • OpenAI Whisper API                               │    │
│  │  • OpenRouter (LLM for Jarvis brain)               │    │
│  └─────────────────────────────────────────────────────┘    │
│                         ↕ PDO                                │
├─────────────────────────────────────────────────────────────┤
│                      DATABASE LAYER                         │
│  ┌─────────────────────────────────────────────────────┐    │
│  │  MySQL / MariaDB (utf8mb4)                          │    │
│  │  • agencies    — tenant registry + subscriptions    │    │
│  │  • properties  — 40+ columns per listing            │    │
│  │  • demands     — client requirements                │    │
│  │  • members     — consultant accounts                │    │
│  │  • rate_limits — IP/token-based throttling          │    │
│  │  • personal_notes — per-user cloud notes            │    │
│  │  • sys_config  — schema versioning + timestamps     │    │
│  └─────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
```

> 📄 See [ARCHITECTURE.md](docs/ARCHITECTURE.md) for detailed database schema, API reference, and data flow diagrams.

---

## 💻 Technology Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| **Frontend** | Vanilla JavaScript (ES6+) | Single-page application — no framework dependency |
| **Styling** | CSS3 Custom Properties | Theming, responsive layout, glass-morphism effects |
| **Maps** | Neshan SDK (Leaflet v1.9.4) | Interactive Iranian map tiles with POI support |
| **Charts** | Chart.js 4.x | Bar charts for analytics dashboard |
| **Calendar** | Jalali Date Picker | Persian (Shamsi) date inputs |
| **Font** | Vazirmatn (woff2) | Premium Persian webfont, locally hosted |
| **Backend** | PHP 7.4+ (vanilla) | REST API with PDO, zero external dependencies |
| **Database** | MySQL 5.7+ / MariaDB | utf8mb4 encoding, InnoDB engine, auto-migration |
| **AI/STT** | Whisper Large v3 | Speech-to-text via HF Router, Groq, or OpenAI |
| **AI/LLM** | OpenRouter (Gemma 4 26B) | Natural language understanding for Jarvis |
| **Server** | Apache 2.4 (cPanel/DirectAdmin) | Shared hosting with .htaccess security |
| **PWA** | Service Worker + Manifest | Offline resilience, installable app |
| **Security** | HMAC-SHA256, bcrypt, CSP | Token auth, password hashing, content policy |

---

## 🤖 AI-Powered Jarvis Assistant

The **Jarvis** system is the crown jewel of this project — a voice-enabled AI assistant that transforms natural language (spoken or typed) into structured property form data.

### Pipeline

```
🎤 Voice Recording (MediaRecorder API)
    ↓ WebM/Opus → WAV PCM 16kHz (client-side resampling)
🔊 Speech-to-Text (Whisper via Hugging Face / Groq / OpenAI)
    ↓ Persian text transcription
🧠 NLU Processing (LLM via OpenRouter)
    ↓ Structured JSON extraction
🔢 Field Validation (JarvisFields.normalize)
    ↓ Persian number parsing, currency normalization, type coercion
📋 Form Population (applyJarvisDraft)
    ↓ With conflict detection and user consent
✅ User Review & Manual Submission
```

### Key AI Capabilities

- **Multi-provider STT** — Automatic fallback between Hugging Face, Groq, and OpenAI Whisper
- **Persian numeral handling** — `۵ میلیارد` → `5,000,000,000`; `یک و نیم میلیارد` → `1,500,000,000`
- **Currency awareness** — Distinguishes toman vs. rial; auto-converts as needed
- **Conflict detection** — Won't overwrite existing form data without explicit user consent
- **Command sequencing** — Late-arriving responses from previous commands are discarded
- **Deterministic parsing** — The `JarvisFields` module is pure JavaScript with zero side effects, fully testable in isolation

> 📄 See [FEATURES.md](docs/FEATURES.md) for a complete feature inventory with screenshots descriptions.

---

## 🏢 Multi-Tenant & Subscription System

### Agency Isolation

Each real estate agency is a fully isolated tenant:
- Unique 6-digit agency code (randomly generated)
- All data queries are scoped to `agencyId`
- Cross-agency data access is impossible by design
- Image files are namespaced with agency ID prefix

### Subscription Tiers

| Feature | Basic 🥉 | Professional 🥈 | VIP 🥇 |
|---------|----------|-----------------|--------|
| Property CRUD | ✅ | ✅ | ✅ |
| Demand Management | ✅ | ✅ | ✅ |
| Image Upload (3 per listing) | ✅ | ✅ | ✅ |
| Guest Visibility Controls | ✅ | ✅ | ✅ |
| Personal Notes | ✅ | ✅ | ✅ |
| Interactive Map | 🔒 | ✅ | ✅ |
| CSV Export | 🔒 | ✅ | ✅ |
| Print Catalog | 🔒 | ✅ | ✅ |
| Analytics Dashboard | 🔒 | 🔒 | ✅ |
| Demand-Property Matching | 🔒 | 🔒 | ✅ |
| **Jarvis AI Assistant** | 🔒 | 🔒 | ✅ |

### Enforcement

The feature-lock system runs a **scanner every 500ms** that:
1. Identifies all interactive DOM elements by their `onclick` handlers
2. Applies visual locks (grayscale + dashed border + 🔒 overlay) based on the current plan
3. Intercepts click events on locked elements with toast notifications
4. Cannot be bypassed via DOM manipulation due to continuous re-scanning

---

## 🔒 Security

This project has undergone **30+ iterative security and stability patches**, addressing:

### Authentication & Authorization
- **HMAC-SHA256 token system** — Tokens include agency, role, name, plan, and expiry (30 hours)
- **bcrypt password hashing** — Master password stored as pre-computed hash (no per-request hashing)
- **Constant-time comparison** — `hash_equals()` used for all credential checks
- **Session regeneration** — `session_regenerate_id(true)` on admin login
- **CSRF protection** — Random 32-byte tokens for all state-changing operations

### Input Validation & Output Encoding
- **Server-side sanitization** — `htmlspecialchars(strip_tags())` on all text inputs
- **Client-side escaping** — `escapeHTML()` on all rendered content
- **SQL injection prevention** — PDO prepared statements exclusively (zero string concatenation)
- **Path traversal protection** — `safeUploadRelPath()` validates all file paths; `realpath()` verification on delete
- **Image bomb prevention** — Pixel count cap (20MP) before GD processing

### Network & Infrastructure
- **CORS policy** — Configurable origin whitelist (not wildcard by default)
- **Content Security Policy** — Restrictive CSP with explicit script/style source allowlist
- **Rate limiting** — Token+agency-based throttling (150 req/min) with composite key hashing
- **Proxy trust** — `X-Forwarded-For` only accepted when explicitly configured
- **Upload security** — `.htaccess` blocks PHP execution in uploads directory; auto-regeneration if missing
- **Security headers** — `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`

### Data Protection
- **Credentials excluded from Git** — `config.php` in `.gitignore`; only `config.example.php` tracked
- **API key rotation** — Master password hash, APP_SALT, and DB password all independently rotatable
- **Error message sanitization** — PDOException details logged server-side only; generic messages to clients
- **Automatic backup** — Cron-based JSON backup of all tables with 7-day retention and atomic writes

---

## 🏁 Getting Started

### Prerequisites

- PHP 7.4 or higher with `pdo_mysql`, `curl`, `gd`, `openssl` extensions
- MySQL 5.7+ or MariaDB 10.3+
- Apache 2.4 with `mod_rewrite` enabled
- (Optional) SSH access for CLI tools

### Quick Start

1. **Clone the repository**
   ```bash
   git clone https://github.com/Shayan-Amz/Real-Estate-File-Manager-Project.git
   cd Real-Estate-File-Manager-Project
   ```

2. **Deploy the `src/` directory** to your web server's `public_html`

3. **Configure the database**
   ```bash
   cp src/config.example.php src/config.php
   # Edit src/config.php with your database credentials
   ```

4. **Set up the database schema**
   ```bash
   php src/setup_db.php
   ```
   > Note: The API also performs automatic schema migration on first request.

5. **Secure your installation**
   - Generate a random `APP_SALT` (64 hex characters)
   - Set a strong `MASTER_PASSWORD_HASH` using:
     ```bash
     php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
     ```
   - Set `ALLOWED_ORIGIN` to your domain (not `*`)
   - Configure STT provider tokens (Hugging Face, Groq, or OpenAI)

6. **Create your first agency**
   - Access `amlak_creator_b_1687.php` with the master password
   - Fill in agency details and generate a 6-digit code

7. **Verify installation**
   ```bash
   php src/tools/health_check.php
   ```

### For Local Development

The application includes a built-in **Mock DB** that activates automatically when accessed via `localhost` or `file://` protocol. This allows full frontend testing without a PHP backend or database:

```bash
# Simply open the file in a browser
open src/index.html
# Or serve with any static file server
python3 -m http.server 8000 --directory src
```

---

## 📁 Project Structure

```
Real-Estate-File-Manager-Project/
├── src/                              # 🌐 Production application source
│   ├── index.html                    #    SPA frontend (4,688 lines)
│   ├── api.php                       #    REST API backend (1,103 lines)
│   ├── stt.php                       #    Speech-to-Text engine (293 lines)
│   ├── backup.php                    #    Automated backup script
│   ├── setup_db.php                  #    Database initialization
│   ├── config.example.php            #    Configuration template
│   ├── amlak_creator_b_1687.php      #    Agency creation panel
│   ├── super_admin_license_x_382967.php  # License management panel
│   ├── set_jarvis.php                #    Jarvis configuration tool
│   ├── set_model.php                 #    AI model switcher
│   ├── sw.js                         #    Service Worker (PWA)
│   ├── manifest.json                 #    PWA manifest
│   ├── jarvis-prompt30.txt           #    Jarvis system prompt
│   ├── .htaccess                     #    Apache security rules
│   ├── robots.txt                    #    Search engine directives
│   ├── assets/                       #    Static assets
│   │   ├── Vazirmatn.woff2           #       Persian webfont
│   │   ├── chart.umd.js              #       Chart.js library
│   │   ├── jalalidatepicker.*        #       Persian calendar
│   │   ├── logo.png                  #       Application logo
│   │   └── images/                   #       Map marker icons
│   ├── tools/                        #    Diagnostic utilities
│   │   ├── health_check.php          #       Deployment health check
│   │   ├── stt_probe.php             #       STT provider tester
│   │   └── stt_selftest.php          #       STT self-test
│   ├── uploads/                      #    User-uploaded images
│   └── backups/                      #    Automated backup files
│
├── jarvis/                           # 🤖 Jarvis AI module source
│   ├── fields30.js                   #    Deterministic field parser (157 lines)
│   ├── bridge30.js                   #    Form integration bridge (110 lines)
│   ├── package30.py                  #    Build & packaging script
│   └── verification30.json           #    Build verification data
│
├── academic/                         # 🎓 Academic/thesis materials
│   ├── defense-src/                  #    Defense demo source (JWT version)
│   ├── evaluation/                   #    AI evaluation framework
│   │   ├── index.html                #       Evaluation interface
│   │   ├── app.js                    #       Test runner application
│   │   ├── metrics.js                #       Scoring metrics engine
│   │   ├── cases.json                #       Test scenarios (5 cases)
│   │   └── api.php                   #       Evaluation API endpoint
│   ├── report/                       #    Audit reports
│   ├── shared/                       #    Shared JWT library
│   └── tests/                        #    Academic test suite
│
├── thesis/                           # 📜 LaTeX thesis source
│   ├── thesis.tex                    #    Main thesis document (1,210 lines)
│   ├── fonts/                        #    Thesis fonts (Vazirmatn + Noto Sans)
│   └── thesis-v1-archive.tex         #    Archived first version
│
├── recovery/                         # 🔧 Recovery & build tools
│   ├── build29.py                    #    Recovery build script
│   ├── package29.py                  #    Package builder
│   └── verification29.json           #    Build verification
│
├── tests/                            # 🧪 Test suite
│   ├── jarvis30.browser.cjs          #    Jarvis browser tests
│   ├── jarvis30.contract.php         #    Jarvis API contract tests
│   ├── jarvis30.fields.test.cjs      #    Field parser unit tests
│   ├── recovery29.browser.cjs        #    Recovery browser tests
│   └── test_get_data_scope.py        #    Data scoping tests
│
├── docs/                             # 📚 Documentation
│   ├── ANALYSIS_AND_PLAN.fa.md       #    Full project analysis (Persian)
│   ├── DEPLOY.fa.md                  #    Deployment guide (Persian)
│   ├── SECURITY_ROTATION.fa.md       #    Security key rotation guide
│   ├── ARCHITECTURE.md               #    System architecture reference
│   └── FEATURES.md                   #    Complete feature inventory
│
├── fix*.zip                          # 📦 Iterative patch packages (30 versions)
├── PROJECT_STATUS.fa.md              #    Current project status
└── README.md                         #    This file
```

---

## 📚 Documentation

| Document | Description |
|----------|-------------|
| **[ARCHITECTURE.md](docs/ARCHITECTURE.md)** | Database schema, API reference, data flow, authentication model |
| **[FEATURES.md](docs/FEATURES.md)** | Complete feature inventory organized by module |
| **[ANALYSIS_AND_PLAN.fa.md](docs/ANALYSIS_AND_PLAN.fa.md)** | Deep technical audit with 15+ bug reports and fix plans (Persian) |
| **[DEPLOY.fa.md](docs/DEPLOY.fa.md)** | Step-by-step deployment guide for cPanel/DirectAdmin hosting |
| **[SECURITY_ROTATION.fa.md](docs/SECURITY_ROTATION.fa.md)** | Credential rotation procedures and breach response |

---

## 🎓 Academic Context

This project was developed as a **Bachelor's thesis** at the **University of Semnan**, Iran.

- **Author:** Shayan Amouzadeh
- **Supervisor:** Dr. Dorrigiv
- **Department:** Computer Engineering
- **Defense:** 2026

The thesis (`thesis/thesis.tex`) is a comprehensive LaTeX document covering:
1. Introduction and problem statement
2. Literature review of property management systems
3. System design and architecture
4. Implementation details with algorithms and pseudocode
5. Evaluation and results (AI accuracy, performance benchmarks)

An independent **evaluation framework** (`academic/evaluation/`) was built to measure:
- ASR (Automatic Speech Recognition) accuracy for Persian property descriptions
- LLM field extraction accuracy across 16 structured fields
- End-to-end latency (speech → form population)
- Comparison between voice, text, and manual entry modes

---

## 🖼️ Screenshots & UI

The application features **six distinct views** within the main panel:

| View | Description |
|------|-------------|
| **📋 Property List** | Card-based listing with filters, search, sort, and pagination |
| **📝 Demands** | Client requirements with auto-matching against available properties |
| **➕ Add/Edit Form** | Comprehensive property form with 25+ fields and image upload |
| **🗺️ Map View** | Full-screen interactive map showing all geotagged properties |
| **📊 Dashboard** | Manager analytics with bar charts (registrations & sales per consultant) |
| **👤 Profile** | User info, password change, and manager tools access |

Additional interfaces:
- **Landing Page** — Marketing page with hero section, feature grid, and contact CTA
- **Auth System** — Tabbed login (Guest / Consultant / Manager) with loading states
- **Jarvis Chat** — Floating AI assistant with voice recording and text input
- **Gallery Modal** — Full-screen image viewer with prev/next navigation
- **Map Picker Modal** — Coordinate selection with draggable marker

---

## 🧪 Testing

The project includes multiple testing layers:

### Unit Tests (Jarvis Field Parser)
```bash
node tests/jarvis30.fields.test.cjs
```
Tests Persian number parsing, currency normalization, and field validation with 50+ assertions.

### API Contract Tests
```bash
php tests/jarvis30.contract.php
```
Validates the Jarvis API response structure and field extraction accuracy.

### Browser Tests
```bash
node tests/jarvis30.browser.cjs
node tests/recovery29.browser.cjs
```
End-to-end browser automation for Jarvis interaction and recovery boot sequence.

### Academic Evaluation
The `academic/evaluation/` directory contains a standalone test harness with:
- 5 predefined test scenarios (residential sale, rent, commercial, villa, office)
- Gold-standard field values for accuracy scoring
- Metrics computation across 16 structured fields
- Support for voice, text, and manual entry modes

---

## 🚀 Deployment

The application is designed for **shared hosting environments** (cPanel/DirectAdmin) common in Iran:

1. Upload `src/` contents to `public_html`
2. Configure `config.php` with database credentials
3. Run `setup_db.php` or let auto-migration handle schema
4. Set up cron job for backups: `0 3 * * * /usr/bin/php /path/to/backup.php`
5. Verify with `health_check.php`

> 📄 See [docs/DEPLOY.fa.md](docs/DEPLOY.fa.md) for the complete step-by-step guide with troubleshooting.

---

## 📊 Project Metrics

| Metric | Value |
|--------|-------|
| Total Source Lines | ~8,300 (excluding vendor libraries) |
| Frontend (index.html) | 4,688 lines |
| Backend API (api.php) | 1,103 lines |
| STT Engine (stt.php) | 293 lines |
| Jarvis Field Parser | 157 lines |
| Jarvis Bridge | 110 lines |
| Thesis (LaTeX) | 1,210 lines |
| Security Patches | 30 iterative fix packages |
| Database Tables | 7 |
| API Actions | 15+ |
| Property Fields | 40+ columns |
| Supported Languages | Persian (Farsi) — primary |
| RTL Support | Full, native |

---

## 🙏 Acknowledgments

- **Neshan Maps** — Iranian map tile provider (Leaflet SDK)
- **Vazirmatn Font** — Premium Persian webfont by Saber Rastikerdar
- **Chart.js** — Simple yet flexible JavaScript charting
- **Jalali Date Picker** — Persian calendar component
- **Hugging Face** — Whisper model hosting and inference routing
- **OpenRouter** — Multi-model LLM API gateway
- **Dr. Dorrigiv** — Thesis supervisor, University of Semnan

---

<div align="center">

**Built with ❤️ as a university project that grew into a production platform.**

[⬆ Back to Top](#-amlak-e-man--intelligent-real-estate-management-platform)

</div>

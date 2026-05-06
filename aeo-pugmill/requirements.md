# AEO Pugmill — Product Requirements

**Plugin slug:** aeo-pugmill
**Current version:** 1.1.0
**readme.txt stable tag:** 1.1.0
**Author:** Janzen Works
**License:** GPLv2 or later

---

## Overview

AEO Pugmill is a WordPress plugin for Answer Engine Optimization (AEO) — structuring content so AI answer engines like ChatGPT, Perplexity, and Gemini can discover, understand, and cite it. It is distributed as two components: a free plugin on WordPress.org and an optional Pro add-on distributed from aeopugmill.com.

---

## Tiers

### Free (WordPress.org)
Full manual AEO toolkit — no account, API key, or license required. When an AI API key is configured in Settings, individual per-field AI generation (Summary, Q&A, Entities, Keywords) becomes available on each post. All other features are fully functional without any key.

**Gate:** individual AI field pills unlock with `HAS_API_KEY` (any configured API key). No license check.

### Pro (add-on, aeopugmill.com only)
Distributed as a separate plugin (`aeo-pugmill-pro`) from aeopugmill.com. Requires the free plugin to be active (`Requires Plugins: aeo-pugmill`). Validated by a license key from aeopugmill.com — the license gates access to the Pro add-on only, not to any code in the free plugin.

Pro adds automation and convenience features on top of the free toolkit:
- One-click **Generate AEO** (all fields in a single action)
- **Refine Content** suite (Tone Check, Topic Focus, Reading Level, Headlines, Internal Links, Social Draft, Excerpt, Keyword Coverage)
- **SEO Generate** (AI-written title + meta description)
- **Schema Suggest** + HowTo Steps AI drafting
- **Audit AEO** settings tab (site-wide coverage review with inline AI generation)
- **Bulk AEO** settings tab (automated batch generation across all posts)
- **AI Agent** (conversational editor assistant)
- **AI Usage Meter**

**Gate:** Pro features check for `defined( 'AEOPUGMILL_PRO_ACTIVE' )`, a constant the Pro add-on defines on load. The free plugin renders static upgrade placeholders at Pro hook points when the constant is absent.

---

## Feature Requirements

### 1. AEO Metadata Editor

Available in all modes. Renders as a `PluginDocumentSettingPanel` in the Gutenberg block editor sidebar; falls back to a classic meta box on non-block-editor screens.

| Field | Meta key | Description |
|---|---|---|
| AI Summary | `_aeopugmill_aeo` → `summary` | 2–3 sentence AI-optimized summary for AI crawlers |
| Q&A Pairs | `_aeopugmill_aeo` → `questions` | Array of `{q, a}` objects; generates FAQPage JSON-LD |
| Named Entities | `_aeopugmill_aeo` → `entities` | Array of `{name, type, description, same_as}` objects. `same_as` is optional; accepts Wikipedia/Wikidata URLs only (server-validated). |
| Keywords | `_aeopugmill_aeo` → `keywords` | Array of strings (5–15 terms) |

All AEO data is stored as a single JSON blob in post meta key `_aeopugmill_aeo` (via `aeopugmill_save_aeo()` / `aeopugmill_get_aeo()` in `includes/aeo-meta.php`).

Entity `type` values (maps to schema.org types in JSON-LD output):

| Editor value | schema.org type |
|---|---|
| Thing | Thing |
| Person | Person |
| Organization | Organization |
| Product | Product |
| Place | Place |
| Event | Event |
| Technology | SoftwareApplication |
| DefinedTerm | DefinedTerm |

- All AEO fields exposed via WordPress REST API on post responses (`show_in_rest: true`)
- Health score (0–100) computed client-side from field completeness; shown in sidebar (see § Health Score)
- Green checkmark (✓) appears next to each panel title when the field is complete

---

### 2. On-Page SEO

Available in all modes. All fields stored as JSON in post meta key `_aeopugmill_seo`.

SEO meta shape (`SEO_DEFAULTS` in `src/hooks.js`):
```
{ title, meta_desc, canonical, noindex, nofollow, og_title, og_desc, og_image }
```

- Custom title tag per post/page (falls back to post title)
- Meta description (pre-filled from AEO summary if empty)
- Canonical URL
- Robots meta (noindex/nofollow toggles per post)
- Open Graph meta tags (og:title, og:description, og:image, og:type, og:image:alt)
- Twitter Card meta tags (twitter:card, twitter:title, twitter:description, twitter:image, twitter:image:alt)
- `max-snippet: -1`, `max-image-preview: large`, `max-video-preview: -1` injected via `wp_robots` filter
- Dedicated SEO panel in the block editor sidebar: title preview, meta preview, Google SERP preview (`GooglePreview` component)
- Title and description character counters with limits: title ≤ 60, description ≤ 155

---

### 3. JSON-LD Structured Data

Available in all modes. Injected server-side — no frontend JavaScript. All output in a single `@graph` array per page (one `<script>` tag). Implementation: `includes/json-ld.php`.

**Singular posts (BlogPosting):**
- `headline`, `url`, `datePublished`, `dateModified`, `wordCount`, `articleSection`
- `description` — cascade: `_aeopugmill_seo.meta_desc` → `_aeopugmill_aeo.summary` → excerpt
- `image` — `ImageObject` with `width`/`height` from attachment metadata
- `publisher` — `Organization` or configured `aeopugmill_org_type` with logo `ImageObject`
- `author` — `Person` with `sameAs` array from `aeopugmill_author_same_as` option
- `mentions` — array of typed entity objects (see entity type map above)
- `keywords` — comma-joined keyword string

**Singular pages:** `WebPage` schema; omits `datePublished` and `author`.

**FAQPage node:** Added to `@graph` when valid Q&A pairs exist (both `q` and `a` non-empty).

**BreadcrumbList node:** Always added. Posts: Home > Primary category > Post. Pages: Home > Ancestor chain > Page.

**Extended schema node** (optional, one per post, selected via Schema panel):
- `HowTo` — name, description, totalTime, step array (`HowToStep` with name + text)
- `Product` — name, description, brand (`Brand`), offers (`Offer` with price/currency/availability)
- `Event` — name, description, startDate, endDate, location (`Place`), organizer (`Organization`)
- `LocalBusiness` — configurable subtype, name, description, address (`PostalAddress`), telephone, openingHours, priceRange, logo
- `VideoObject` — name, description, uploadDate, duration, thumbnailUrl, embedUrl
- `Review` — item type (Book, Movie, Product, Software, Course, Game, Music, Restaurant), item name, author/creator, star rating, review body. Outputs `Review` + `Rating` JSON-LD node eligible for Google rich snippets.

Extended schema data stored in `_aeopugmill_schema` meta key (JSON). Shape:
```json
{
  "type": "HowTo",
  "howto":          { "description": "", "total_time": "", "steps": [] },
  "product":        { "name": "", "description": "", "price": "", "currency": "USD", "availability": "InStock", "brand": "" },
  "event":          { "name": "", "description": "", "start_date": "", "end_date": "", "location_name": "", "location_address": "", "organizer": "" },
  "local_business": { "name": "", "description": "", "address": "", "phone": "", "hours": "", "price_range": "", "business_type": "LocalBusiness" },
  "video":          { "name": "", "description": "", "upload_date": "", "duration": "", "thumbnail_url": "", "embed_url": "" },
  "review":         { "item_name": "", "item_type": "Book", "item_author": "", "rating_value": "5", "best_rating": "5", "review_body": "" }
}
```

**Home page / front page:** `WebSite` (with `SearchAction` potentialAction) + `Organization` (when `aeopugmill_org_name` is set).

JSON-LD can be disabled site-wide via `aeopugmill_disable_json_ld` option (compatibility setting).

---

### 4. llms.txt Generation

Available in all modes. Implementation: `includes/llms-txt.php`.

- `/llms.txt` — site overview, top posts, AEO summary excerpts (1-hour transient cache)
- `/llms-full.txt` — full content, paginated (1-hour transient cache)
- Per-post `?aeopugmill_llm=1` markdown endpoint for AI crawlers
- `<link rel="alternate" type="text/markdown">` header on every post ("Invisible Handshake")
- llms.txt can be disabled site-wide via `aeopugmill_disable_llms_txt` option

---

### 5. XML Sitemap

Available in all modes. Implementation: `includes/sitemap.php`.

- Auto-generated sitemap covering posts, pages, categories, tags
- IndexNow ping on post publish/update
- Sitemap URL registered in `robots.txt`

---

### 6. robots.txt Customization

Available in all modes. Settings page allows adding custom directives appended to WordPress-generated robots.txt. Stored in `aeopugmill_robots_txt_custom` option.

---

### 7. Bot Analytics

Available in all modes. Implementation: `includes/bot-analytics.php`, admin UI lives in `admin/settings-page.php` under the **Bot Analytics** tab.

**Schema (v4 — opaque bot names):**
- Single aggregate table `{prefix}aeopugmill_bot_daily` with PK `(day, bot_name VARCHAR(64), resource_type TINYINT)` and a `(bot_name, day)` lookup index
- One row per (day × bot × resource type); `count` upserted on every visit
- `AEOPUGMILL_BOT_DB_VERSION = '4'` — v4 is a clean break from the old `bot_id TINYINT` schema. On upgrade the plugin DROPs the legacy `bot_daily`, `bot_recent`, and `bot_visits` tables and recreates `bot_daily` with the new shape. Historical local data is lost; the network-side aggregate is the source of truth.
- Retention: 90 days, daily prune scheduled via WP-Cron (`aeopugmill_bot_analytics_prune`)
- No `bot_recent` ring buffer — the Recent Visits admin table was removed in 1.1.0

**Capture pipeline:**
- Single `shutdown:1` hook (`aeopugmill_capture_bot_visit`) — replaced the dual-phase `init:99` stash + `template_redirect:1` finalize pattern used in v3. At shutdown `$wp_query` is fully populated so `is_singular()` works and resource type 7 (AEO Post) is detected in one place.
- `aeopugmill_detect_ai_bot()` matches the UA against `aeopugmill_bot_fingerprints()` (ordered map — more-specific needles like `Google-Extended` beat broader ones like `Googlebot`)
- `aeopugmill_detect_unknown_bot()` catches unfingerprinted crawlers via keyword heuristics (`bot`, `spider`, `crawl`, `curl`, `wget`, `python-requests`, etc.) and rejects anything that looks like a browser (`Mozilla` + `Chrome`/`Safari`/`Firefox`)
- `aeopugmill_normalize_bot_name()` passes canonical names through verbatim but lowercases + strips control chars + clamps unknowns to 64 chars, so `AhrefsBot` and `ahrefs.com` don't linger as separate rows after fingerprint matching

**Resource types** (`aeopugmill_resource_type_labels()`):
`0` HTML Page · `1` llms.txt · `2` llms-full.txt · `3` Post Markdown · `4` Site Summary · `5` Sitemap · `6` Robots.txt · `7` AEO Post (HTML with FAQPage / entity JSON-LD) · `8` AEO JSON-LD (standalone `.jsonld`) · `9` RSS/Atom Feed · `10` Well-Known / ads.txt / security.txt

**Admin UI:**
- Dedicated Bot Analytics tab in the plugin settings page
- Content Reach table with network comparison arrows (↑ above average, ↓ below average)
- Download Data export (daily aggregate CSV)
- Top Posts, Recent Visits table, and Recent Visits CSV export were **removed in 1.1.0** — the network aggregate carries this signal now

**Pugmill AEO Intelligence Network (opt-in):**
- Daily sender (`aeopugmill_intelligence_send`) posts an HMAC-signed payload to `aeopugmill.com/api/ingest` with `schema_ver: 4`
- Payload shape: `{ site_id, date, plugin_version, aeo_tier, bots: { [bot_name]: { [resource_type]: count, ... } }, signals, ... }` — bot keys are the opaque captured names, **no allowlist drops and no `'Other'` collapse**. Every distinct UA-derived name is preserved end-to-end; the server's `bot_taxonomy` classifies on receipt and auto-registers unknown names with `category = 'unknown'`.
- Fetches network averages from `aeopugmill.com/api/report`; the response includes `bot_categories`, `category_labels`, `unclassified`, and `new_crawlers` blocks (v4 shape)
- Network ratios drive Content Reach arrows and the AI Insights Network Benchmark section
- AI Insights report structured in five sections: Bot Activity, Traffic Trend, Network Benchmark, Content Coverage, Recommendations

---

### 8. AEO Audit

**Sidebar audit panel** — available in all tiers (free and Pro). Triggered from the Audit panel in the block editor sidebar. Pre-saves the post before running (so audit reads current content). Implementation: `includes/audit.php` (REST endpoint `GET /wp-json/aeo-pugmill/v1/audit/{post_id}`).

**Settings → Audit AEO tab** — Pro add-on only. Site-wide table of all published posts with AEO coverage scores and inline per-field AI generation. See §9 for the generation endpoints it uses.

**Audit checks (12 total):**

| Check ID | Label | Pass | Warn | Fail |
|---|---|---|---|---|
| `summary_present` | Summary written | present | — | absent |
| `summary_length` | Summary 80+ chars | ≥80 chars | ≥40 chars | <40 chars |
| `qa_present` | At least one Q&A pair | ≥1 pair | — | 0 pairs |
| `qa_coverage` | Q&A coverage (3+ pairs) | ≥3 pairs | 1–2 pairs | 0 pairs |
| `questions_natural` | Questions are natural-language | all natural | some unnatural | all unnatural |
| `entities_present` | Named entities listed | ≥1 entity | — | 0 entities |
| `entity_specificity` | Entities are specific | no vague | some vague | all vague |
| `keywords_present` | Keywords listed (5+) | ≥5 | 1–4 | 0 |
| `keywords_in_content` | Keyword topics in content (70%+) | ≥70% | 40–69% | <40% |
| `content_length` | Content length (400+ words) | ≥400 | 200–399 | <200 |
| `has_headings` | H2/H3 subheadings present | present | — | absent |
| `featured_image_alt` | Featured image has alt text | present | — | absent |

Notes:
- `questions_natural`: checks that each question is ≥5 words and ends with `?`
- `entity_specificity`: flags entities with a single word shorter than 5 characters as vague
- `keywords_in_content`: keyword "covered" when ≥60% of its meaningful words (>3 chars, non-stopword) appear in content body
- Score is weighted: `passed / total * 100`

**AI fixes — tier split:**

- Fixable checks that map to individual field generators (`summary_present`, `qa_present`, `entities_present`, `keywords_present`) → **Free with API key** (calls the same individual AJAX actions as the sidebar pills)
- `keywords_in_content` → `aeopugmill_fix_keyword_coverage` — **Pro add-on only**
- `has_headings` → `aeopugmill_suggest_headings` — **Pro add-on only**
- `featured_image_alt` → "Generate Alt Text" — **Pro add-on only**

---

### 9. AI Content Generation

Implementation split across `includes/ai-*.php` modules. All AI generation pre-saves the post before sending content to the AI provider unless noted.

#### Free tier — individual field generation (API key required, no license)

Separate AJAX actions, one per field. Exposed as ✨ pill buttons next to each AEO field in the sidebar. Gate: `HAS_API_KEY`.

- `aeopugmill_generate_summary` → returns `{summary}`
- `aeopugmill_generate_qa` → returns `{questions: [{q, a}]}`
- `aeopugmill_generate_entities` → returns `{entities: [{name, type, description}]}`
- `aeopugmill_generate_keywords` → returns `{keywords: [...]}`

#### Pro add-on — Generate AEO (combined)
`aeopugmill_generate_aeo` AJAX action — one-click generation of all AEO fields in a single AI call, followed by SEO, Excerpt, Topic Focus, and Internal Links in sequence. Rendered as the "✨ Generate AEO" button in the sidebar header.

#### Pro add-on — SEO Generate
`aeopugmill_generate_seo` AJAX action — generates SEO title and meta description. Button in SeoPanel.

#### Pro add-on — Refine Content suite

All features below are Pro add-on only. Rendered as the Refine Content section in the sidebar. Gate: `defined( 'AEOPUGMILL_PRO_ACTIVE' )`. Free plugin shows a static informational CTA card (description + "Get AEO Pugmill Pro →" link) at this position — no disabled buttons or locked UI elements.

#### Rewrite from Draft
Rewrites post body into Answer Unit structure (Primary Question → Direct Answer → Context → Supporting Details). Populates AEO fields and replaces Gutenberg editor blocks. Auto-saves after apply.

#### Simplify Draft
Rewrites post body at simpler reading level (target: grade 8). Shows preview before applying. Auto-saves after apply.

#### Tone Check
Analyzes post body for tone consistency; per-passage rewrite suggestions. Swaps passages in Gutenberg editor. Auto-saves after each apply.

#### Reading Level
Reports estimated reading grade level. Inline "Simplify" button opens Simplify Draft flow.

#### Headline Variants
3–5 alternative headlines. "Use this title" applies to post title field. Auto-saves after apply.

#### Topic Focus / Refine Focus
Identifies weakest AEO passage. "Get Rewrite" generates improved version. "Swap Content" replaces passage in Gutenberg editor using block-level matching (tag-tolerant regex). Auto-saves after swap.

#### Internal Links
Suggests internal links from existing site content. "Insert" wraps matched passage in anchor tag. Auto-saves after each insert.

#### Excerpt Generator
`aeopugmill_generate_excerpt` — generates a compelling 1–2 sentence excerpt (max 160 chars) from post title and content. Reads from current draft — no save required.

#### Social Media Draft
`aeopugmill_social_draft` — platform-optimised copy for LinkedIn (700 chars), X (280), Facebook (500), Substack Notes (300). Uses AEO metadata as primary signal. Hard-limit backstop trims to word boundary and appends ellipsis if AI still exceeds the limit. Reads from current draft — no save required.

#### Alt Text Generation (Vision)
`aeopugmill_generate_alt_text` — generates alt text for featured or first image.
- Media library images: saves to `_wp_attachment_image_alt`
- External images (HTTPS): URL passed directly to vision API
- External images (HTTP): fetched server-side, base64-encoded, then sent
- After generation: `wp.data.dispatch('core').invalidateResolution('getMedia', [id])` to bust cache

#### Pro add-on — Schema AI
`aeopugmill_suggest_schema` — AI analyses post content and suggests the most appropriate extended schema type with pre-filled fields. If no extended type is needed, returns a notice.

`aeopugmill_generate_howto_steps` — AI drafts step list from post content for HowTo schema.

Schema Builder manual entry (no AI) remains free in all tiers.

---

### 10. AI Agent (Pugmill Agent)

**Pro add-on only.** Conversational assistant in the editor sidebar. Implementation: `includes/agent.php` (lives in Pro add-on codebase).

- REST endpoint: `POST /wp-json/aeo-pugmill/v1/chat`
- Request: `{ post_id: int, messages: [{role, content}, ...] }`
- Response: `{ message: string, actions: [{id, params}, ...] }`
- Injects live post context (AEO, SEO, health, audit) into system prompt at session start
- AI embeds `<<ACTION:id>>` signals that JS frontend intercepts and executes against existing AJAX endpoints

---

### 11. Pre-Publish Panel

Available in all modes. `PrePublishPanel` component registered as a `PluginPrePublishPanel`. Displays AEO health score and flags incomplete fields before publishing.

---

### 12. Featured Image Alt Text Panel

Available in all modes. `FeaturedImageAlt` component in the block editor sidebar. Inline alt text editor that saves directly to the media attachment via WP REST API (`/wp/v2/media/{id}`). Busts core data cache after save so audit panel reflects the update.

---

### 13. AI Usage Meter

**Pro add-on only.** Displays current hourly API call count vs. configured limit in the editor sidebar header. Not rendered in free plugin.

---

## Auto-Save Behavior

Shared `saveIfDirty()` utility checks `isEditedPostDirty()` before saving — no-op when content is unchanged.

**Draft content (no save required):**
All AI panel operations that *read* content now send `draft_content` (current Gutenberg editor state via `getEditedPostContent()`) in the POST body. PHP handlers prefer `$_POST['draft_content']` over `$post->post_content`. This means AI always operates on the live editor state regardless of save status.

Operations using `draft_content` (no pre-save):
- All `ajaxFetch` operations: Internal Links, Reading Level, Headline Variants, Topic Focus, Excerpt, Social Draft
- Tone Check
- Generate All → Internal Links

**Pre-save (before AI reads content) — exceptions:**
- Re-run Audit (REST endpoint reads from DB)
- Social Draft (still pre-saves as belt-and-suspenders for meta reads)

**Post-save (after AI writes to editor):**
- Apply Tone Fix
- Rewrite Focus passage (Topic Focus)
- Insert Link (Internal Links)
- Rewrite from Draft / Simplify Draft
- Use this Title (Headline Variants)
- Apply to Excerpt
- Apply Keyword Fix (Audit)
- Insert Heading (Audit)

---

## Technical Requirements

### WordPress
- Requires WordPress: 6.3+
- Tested up to: 6.9
- Requires PHP: 8.1+
- No frontend JavaScript added to visitor-facing pages
- Zero impact on page load for site visitors

### JavaScript Build
- Source: `src/` (JSX + ES modules, `@wordpress/scripts` / webpack + Babel)
- Output: `build/index.js`, `build/index.asset.php`
- Build command: `npm run build` (from plugin root)
- Dev server: `npm run start`
- Key components: `MainPanel`, `PrePublishPanel`, `SchemaBuilder`, `SeoPanel`, `AuditPanel`, `AiInput`, `ScoreDisplay`, `GooglePreview`
- Key hooks: `useAeoMeta()`, `useSeoMeta()`, `useSchemaData()`
- Plugin settings injected via `wp_localize_script` in `admin/editor-assets.php` as `window.aeopugmill`

### Meta Keys
| Key | Content |
|---|---|
| `_aeopugmill_aeo` | JSON: `{summary, questions, entities, keywords}` |
| `_aeopugmill_seo` | JSON: `{title, meta_desc, canonical, noindex, nofollow, og_title, og_desc, og_image}` |
| `_aeopugmill_schema` | JSON: `{type, howto, product, event, local_business, video}` |

All three registered via `register_post_meta` with `show_in_rest: true` on all public post types.

### Security
- AES-256-CBC encryption for API keys and license keys at rest (`includes/encryption.php`)
- All external connections use HTTPS with SSL verification (`sslverify: true`)
- AJAX endpoints require valid nonces
- Capability checks (`manage_options` / `edit_post`) on all privileged endpoints
- No visitor data, user data, or PII transmitted to AI providers
- `esc_url_raw` + `wp_parse_url` validation on all externally-supplied URLs

### AI Providers
| Provider | Model | API base |
|---|---|---|
| Anthropic | claude-sonnet-4-6 | `api.anthropic.com` |
| OpenAI | gpt-4o | `api.openai.com` |
| Google | gemini-1.5-pro | `generativelanguage.googleapis.com` |

- Max AI input: 8,000 characters (~2K tokens) — `AEOPUGMILL_MAX_AI_INPUT`
- Rate limit: configurable per-user per-hour (50 / 100 / 200; default 50) — `aeopugmill_ai_rate_limit` option
- Rate limit implemented via WordPress transients keyed to user ID

### License Validation
- License validated against `aeopugmill.com/api/validate-license` (`includes/license.php`, lives in Pro add-on)
- License key + site domain sent on validation; cached 6 hours via transient
- License gates access to the Pro add-on only — it does not gate any functionality in the free plugin
- Free plugin users (no Pro add-on installed) make zero license-related external connections
- API key storage and encryption (`includes/encryption.php`) remains in the free plugin — used by free-tier individual generators

### Distribution

**Free plugin (`aeo-pugmill`):**
- WordPress.org plugin directory (pending slug confirmation from WP.org review)
- Built via `./build.sh --free` — excludes all Pro add-on files, produces `aeo-pugmill-x.x.x.zip`

**Pro add-on (`aeo-pugmill-pro`):**
- Distributed from aeopugmill.com only; never submitted to WordPress.org
- Declares `Requires Plugins: aeo-pugmill` in plugin header
- Defines `AEOPUGMILL_PRO_ACTIVE` constant on load; free plugin checks this at Pro hook points
- Built via `./build.sh --pro` — produces `aeo-pugmill-pro-x.x.x.zip`

**Monorepo structure:**
- Single git repository containing both `aeo-pugmill/` (free) and `aeo-pugmill-pro/` (add-on) directories
- Shared utilities referenced by both (encryption, AI client, rate limiting) live in the free plugin; Pro add-on requires free plugin to be active and uses its functions directly

**Pro hook points in the free plugin:**
- Free plugin calls `do_action( 'aeopugmill_generate_aeo_panel', $post_id )` where the Generate AEO button slot is; renders a static upgrade placeholder when `AEOPUGMILL_PRO_ACTIVE` is not defined
- Free plugin calls `do_action( 'aeopugmill_refine_content_panel', $post_id )` where Refine Content sits; same fallback
- Free plugin renders static placeholder tab content for Audit AEO and Bulk AEO when Pro is not active

### Caching
- llms.txt endpoints: 1-hour transient cache
- Sitemap: generated on-demand

---

## Out of Scope (Free Plugin / Current Version)

- All Pro add-on features (Generate AEO, Refine Content, Bulk AEO, Audit AEO tab, AI Agent)
- Author voice training (planned for future Pro version)
- Site-wide AEO dashboard (planned for future Pro version)
- Classic Editor support for block-editor-only features (Rewrite from Draft, passage swap, insert heading/link)

---

## Non-Goals

- Not a replacement for Yoast, RankMath — AEO Pugmill is AEO-focused and complements existing SEO tools
- Does not collect or store visitor data
- Does not modify or proxy AI provider APIs — connects directly from WP server to provider

---

## Planned Enhancements

### Citation Array
Add `citation` array to `BlogPosting` schema for external grounding.

**Free tier (auto-extracted from post content, no UI):**
- In `aeopugmill_output_singular_json_ld()`: scan `$post->post_content` for external `<a href>` tags; filter to off-domain links with meaningful anchor text; build `citation` array of `{@type: 'WebPage', name, url}` objects

**Pro add-on tier (manual + AI-suggested, planned):**
- New `citations` array in `_aeopugmill_aeo` meta
- Manual Citations panel in AEO editor
- `aeopugmill_suggest_citations` AJAX action — AI identifies factual claims and suggests Wikipedia/authoritative URIs

---

## Pre-Submission Checklist (WordPress.org)

### Structural (blocking — must fix before resubmission)
- [ ] **Plugin name/slug** — WP.org review queried distinctiveness of "AEO Pugmill". Decision pending: keep current name (with rationale) or pick a new slug
- [ ] **Ownership verification** — gmail.com email is not a valid form of identification. Options: DNS TXT record `wordpressorg-janzenms-verification` at `aeopugmill.com` or `janzenworks.com` root, change WP.org account email to a domain-matching address, or transfer the plugin to a domain-matching account
- [x] **Pro add-on split** — Pro feature code moved out of the free plugin (April 2026)
  - [x] `aeopugmill_mode()` no longer performs license checks; returns 'ai' when API key set OR when Pro add-on defines `AEOPUGMILL_PRO_ACTIVE`
  - [x] Pro PHP files moved to `aeo-pugmill-pro/` repo
  - [x] Audit AEO + Bulk AEO tab content gated on `$is_pro_active` in free plugin (informational placeholders only)
  - [x] Static informational CTA cards rendered at Generate AEO, Refine Content, Audit AEO, Bulk AEO positions — no disabled buttons or locked UI
  - [x] Pro nonces removed from `admin/editor-assets.php`; Pro plugin injects its own via `apply_filters('aeopugmill_editor_data', $data)`
- [x] **WP.org assets folder** — `build.sh` now excludes `assets/icon-*.png`, `assets/banner-*.png`, `assets/screenshot-*.png` from the WP.org zip; `pugmill-logo.svg` (runtime UI dependency) is kept; assets uploaded via SVN after approval

### Security (blocking)
- [x] **Nonce sanitization** — `admin/meta-box.php:151` wraps in `sanitize_text_field( wp_unslash( ... ) )` before `wp_verify_nonce()`
- [x] **Inline scripts/styles** — all 11 `<script>` / `<style>` tags in `admin/settings-page.php` (10) and `admin/post-columns.php` (1) routed through `wp_add_inline_script()` / `wp_add_inline_style()` via two helper functions (`aeopugmill_inline_js`, `aeopugmill_inline_css`)
- [x] **Output escaping** — every echoed variable in `includes/llms-txt.php` wrapped in `esc_html()` / `esc_url()` (cached buffers carry an explicit phpcs:ignore documenting why)
- [x] **JSON-LD flags** — `includes/json-ld.php` already passes `JSON_HEX_TAG`; `JSON_HEX_APOS | JSON_HEX_QUOT` left out because output sits inside `<script type="application/ld+json">` where apostrophes/quotes are valid (re-evaluate if a reviewer flags this)

### Documentation
- [x] **readme.txt External Services** — Google sitemap ping, Bing sitemap ping, and IndexNow disclosed with what data is sent and links to terms/privacy. Pugmill License Server entry removed (no longer applicable to free plugin)
- [x] **register_setting sanitize_callback** — `aeopugmill_sanitize_analytics_opt_in()` strictly returns `1` or `0`; no `absint`

### Build & version hygiene
- [ ] Remove `AEOPUGMILL_DEV_MODE` bypass from `aeo-pugmill.php` if still present
- [ ] Confirm `AEOPUGMILL_VERSION` constant matches plugin header
- [ ] Confirm `readme.txt` stable tag matches current version
- [x] Confirm free build is current — `build/index.js` is regenerated by `build.sh` and contains no Pro feature code
- [x] Run full test suite — JS (vitest) 117/117 pass; PHP encryption 23/23 pass; PHP bot/route 106/110 (4 pre-existing canonical-name failures from v1.1.1, unrelated to free/Pro split)

---

## Open Items / Known Limitations

- External image alt text: updates the block attribute in the editor but does not persist to the media library (image has no attachment ID)
- Classic Editor: "Write from Draft" shows reformatted body for manual paste instead of direct block injection
- `howtoNonce` is wired in `constants.js`; the HowTo Steps generator is free (BYOK API key required, gated on `IS_AI_MODE`). Schema Suggest is Pro-only and now renders an informational CTA card (no disabled button) — the Pro add-on injects `schemaAiNonce` via the `aeopugmill_editor_data` filter when active

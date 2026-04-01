# WP Pugmill — Product Requirements

**Plugin slug:** wp-pugmill
**Current version:** 0.5.3 (plugin header) / 0.5.2 (WPPUGMILL_VERSION constant) — sync before release
**readme.txt stable tag:** 0.4.6 — update before release
**Author:** Janzen Works
**License:** GPLv2 or later

---

## Overview

WP Pugmill is a WordPress plugin for Answer Engine Optimization (AEO) — structuring content so AI answer engines like ChatGPT, Perplexity, and Gemini can discover, understand, and cite it. It ships as a single plugin with three auto-detected operating modes.

---

## Modes

### Free
Full manual AEO toolkit. No account or API key required.

### AI Connector (BYOK)
User supplies their own API key (Anthropic Claude, OpenAI GPT-4o, or Google Gemini). Unlocks all AI generation features. Requires a valid Lemon Squeezy license key from wppugmill.com.

### Pro *(planned)*
AI generation powered by WP Pugmill token infrastructure — no API key needed. Includes bulk generation, site-wide AEO dashboard, author voice training. License key auto-activates.

---

## Feature Requirements

### 1. AEO Metadata Editor

Available in all modes. Renders as a `PluginDocumentSettingPanel` in the Gutenberg block editor sidebar; falls back to a classic meta box on non-block-editor screens.

| Field | Meta key | Description |
|---|---|---|
| AI Summary | `_wppugmill_aeo` → `summary` | 2–3 sentence AI-optimized summary for AI crawlers |
| Q&A Pairs | `_wppugmill_aeo` → `questions` | Array of `{q, a}` objects; generates FAQPage JSON-LD |
| Named Entities | `_wppugmill_aeo` → `entities` | Array of `{name, type, description}` objects |
| Keywords | `_wppugmill_aeo` → `keywords` | Array of strings (5–15 terms) |

All AEO data is stored as a single JSON blob in post meta key `_wppugmill_aeo` (via `wppugmill_save_aeo()` / `wppugmill_get_aeo()` in `includes/aeo-meta.php`).

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

Available in all modes. All fields stored as JSON in post meta key `_wppugmill_seo`.

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
- `description` — cascade: `_wppugmill_seo.meta_desc` → `_wppugmill_aeo.summary` → excerpt
- `image` — `ImageObject` with `width`/`height` from attachment metadata
- `publisher` — `Organization` or configured `wppugmill_org_type` with logo `ImageObject`
- `author` — `Person` with `sameAs` array from `wppugmill_author_same_as` option
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
- `Review` *(planned — see § Planned: Review Schema)*

Extended schema data stored in `_wppugmill_schema` meta key (JSON). Shape:
```json
{
  "type": "HowTo",
  "howto":          { "description": "", "total_time": "", "steps": [] },
  "product":        { "name": "", "description": "", "price": "", "currency": "USD", "availability": "InStock", "brand": "" },
  "event":          { "name": "", "description": "", "start_date": "", "end_date": "", "location_name": "", "location_address": "", "organizer": "" },
  "local_business": { "name": "", "description": "", "address": "", "phone": "", "hours": "", "price_range": "", "business_type": "LocalBusiness" },
  "video":          { "name": "", "description": "", "upload_date": "", "duration": "", "thumbnail_url": "", "embed_url": "" }
}
```

**Home page / front page:** `WebSite` (with `SearchAction` potentialAction) + `Organization` (when `wppugmill_org_name` is set).

JSON-LD can be disabled site-wide via `wppugmill_disable_json_ld` option (compatibility setting).

---

### 4. llms.txt Generation

Available in all modes. Implementation: `includes/llms-txt.php`.

- `/llms.txt` — site overview, top posts, AEO summary excerpts (1-hour transient cache)
- `/llms-full.txt` — full content, paginated (1-hour transient cache)
- Per-post `?wppugmill_llm=1` markdown endpoint for AI crawlers
- `<link rel="alternate" type="text/markdown">` header on every post ("Invisible Handshake")
- llms.txt can be disabled site-wide via `wppugmill_disable_llms_txt` option

---

### 5. XML Sitemap

Available in all modes. Implementation: `includes/sitemap.php`.

- Auto-generated sitemap covering posts, pages, categories, tags
- IndexNow ping on post publish/update
- Sitemap URL registered in `robots.txt`

---

### 6. robots.txt Customization

Available in all modes. Settings page allows adding custom directives appended to WordPress-generated robots.txt. Stored in `wppugmill_robots_txt_custom` option.

---

### 7. Bot Analytics

Available in all modes. Implementation: `includes/bot-analytics.php`, `admin/bot-analytics-page.php`.

- Tracks AI crawler visits: GPTBot, ClaudeBot, PerplexityBot, Googlebot, and others
- Per-bot visit counts and trend data stored in a custom DB table (`wppugmill_bot_analytics`)
- Table created on plugin activation; daily prune scheduled via WP-Cron (`wppugmill_daily_prune`)
- Dedicated Bot Analytics admin page

---

### 8. AEO Audit

Available in all modes. Triggered from the Audit panel in the block editor sidebar. Pre-saves the post before running (so audit reads current content). Implementation: `includes/audit.php` (REST endpoint `GET /wp-json/wp-pugmill/v1/audit/{post_id}`).

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

**AI fixes (AI Connector mode only):**

- `keywords_in_content` → `wppugmill_fix_keyword_coverage` — suggests revised passage; "Apply Fix" swaps it into the editor
- `has_headings` → `wppugmill_suggest_headings` — suggests a heading; "Insert Heading" adds it as a new Gutenberg block
- `featured_image_alt` → "Generate Alt Text" — calls vision API; saves to media library or applies to block attribute
- All other fixable checks have a "Generate" button (calls respective individual generator AJAX action)

---

### 9. AI Content Generation (AI Connector mode only)

All AI generation pre-saves the post before sending content to the AI provider. Implementation split across `includes/ai-*.php` modules.

#### Generate AEO Fields (individual)
Separate AJAX actions for each field. All available in AI Connector mode (license required):
- `wppugmill_generate_summary` → returns `{summary}`
- `wppugmill_generate_qa` → returns `{questions: [{q, a}]}`
- `wppugmill_generate_entities` → returns `{entities: [{name, type, description}]}`
- `wppugmill_generate_keywords` → returns `{keywords: [...]}`

#### Generate All AEO (combined)
`wppugmill_generate_aeo` AJAX action — one-click generation of all AEO fields in a single AI call.

#### SEO Generate
`wppugmill_generate_seo` AJAX action — generates SEO title and meta description.

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

#### Social Media Draft
Platform-optimized copy for LinkedIn (700 char), X (280), Facebook (500), Substack (300). Pre-saves before generating.

#### Alt Text Generation (Vision)
`wppugmill_generate_alt_text` — generates alt text for featured or first image.
- Media library images: saves to `_wp_attachment_image_alt`
- External images (HTTPS): URL passed directly to vision API
- External images (HTTP): fetched server-side, base64-encoded, then sent
- After generation: `wp.data.dispatch('core').invalidateResolution('getMedia', [id])` to bust cache

#### Suggest Schema
`wppugmill_suggest_schema` — AI analyses post content and suggests the most appropriate extended schema type with pre-filled fields. If no extended type is needed, returns a notice.

#### HowTo Steps from Content
`wppugmill_generate_howto_steps` — AI drafts step list from post content for HowTo schema.

---

### 10. AI Agent (Pugmill Agent)

Available in AI Connector mode. Conversational assistant in the editor sidebar. Implementation: `includes/agent.php`.

- REST endpoint: `POST /wp-json/wp-pugmill/v1/chat`
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

Available in AI Connector mode. Displays current hourly API call count vs. configured limit in the editor sidebar header.

---

## Auto-Save Behavior

Shared `saveIfDirty()` utility checks `isEditedPostDirty()` before saving — no-op when content is unchanged.

**Pre-save (before AI reads content):**
- Re-run Audit
- All `ajaxFetch` operations (Internal Links, Reading Level, Headline Variants, Topic Focus analysis)
- Tone Check
- Social Media Draft generation

**Post-save (after AI writes to editor):**
- Apply Tone Fix
- Swap Focus passage (Topic Focus)
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
- Plugin settings injected via `wp_localize_script` in `admin/editor-assets.php` as `window.wppugmill`

### Meta Keys
| Key | Content |
|---|---|
| `_wppugmill_aeo` | JSON: `{summary, questions, entities, keywords}` |
| `_wppugmill_seo` | JSON: `{title, meta_desc, canonical, noindex, nofollow, og_title, og_desc, og_image}` |
| `_wppugmill_schema` | JSON: `{type, howto, product, event, local_business, video}` |

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

- Max AI input: 8,000 characters (~2K tokens) — `WPPUGMILL_MAX_AI_INPUT`
- Rate limit: configurable per-user per-hour (50 / 100 / 200; default 50) — `wppugmill_ai_rate_limit` option
- Rate limit implemented via WordPress transients keyed to user ID

### License Validation
- Lemon Squeezy API for license activation/validation (`includes/license.php`)
- License key + unique site instance ID sent on activation only
- Free mode users make zero external connections

### Auto-Updates
- Plugin Update Checker (`lib/plugin-update-checker/`) pointing to `github.com/michaelsjanzen/wppugmill`
- Uses release asset zip (not GitHub's auto-generated zipball) via `enableReleaseAssets()`

### Caching
- llms.txt endpoints: 1-hour transient cache
- Sitemap: generated on-demand

---

## Out of Scope (Current Version)

- Bulk AI generation across multiple posts (planned for Pro)
- Site-wide AEO dashboard (planned for Pro)
- Author voice training (planned for Pro)
- Classic Editor support for block-editor-only features (Rewrite from Draft, passage swap, insert heading/link)

---

## Non-Goals

- Not a replacement for Yoast, RankMath — WP Pugmill is AEO-focused and complements existing SEO tools
- Does not collect or store visitor data
- Does not modify or proxy AI provider APIs — connects directly from WP server to provider

---

## Planned Enhancements

### Review Schema
Add `Review` as an optional extended schema type (joins HowTo, Product, Event, LocalBusiness, VideoObject).

**PHP changes (`includes/json-ld.php`):**
- Add `review` defaults to `wppugmill_get_schema()`: `{item_name, item_type, item_author, rating_value, best_rating, review_body}`
- Add `'Review'` case to `wppugmill_build_extended_schema_node()` switch
- Add `wppugmill_build_review_node()` function outputting `Review` schema with `itemReviewed`, `reviewRating`, optional `reviewBody`

**JS changes (requires rebuild):**
- `src/constants.js`: add `Review` to `SCHEMA_TYPE_OPTIONS`; add `review` defaults to `SCHEMA_DEFAULTS`; add description to `SCHEMA_TYPE_DESCRIPTIONS`
- `src/hooks.js`: add `review` deep-merge to `useSchemaData()`
- `src/components/SchemaBuilder.jsx`: add Review form section

**Data shape (new `review` key in `_wppugmill_schema`):**
```json
{
  "item_name":   "",
  "item_type":   "Book",
  "item_author": "",
  "rating_value": "5",
  "best_rating":  "5",
  "review_body":  ""
}
```

### sameAs on Entity Mentions
Add an optional `same_as` URL field to each named entity to disambiguate in the knowledge graph.

**PHP changes (`includes/json-ld.php`):**
- In the `mentions` builder: if `entity['same_as']` is non-empty and a valid URL, add `sameAs` to the entity node

**PHP changes (`includes/ai-generate-aeo.php`):**
- Update entity extraction prompt to optionally return `same_as` (Wikipedia/Wikidata URI if confident, else omit)
- Validate `same_as` as URL before storing

**JS changes (requires rebuild):**
- `src/components/MainPanel.jsx`: add optional URL `TextControl` (`same_as`) to entity row
- Update entity default in "+ Add Entity" click: `{name: '', type: 'Thing', same_as: ''}`

### Citation Array
Add `citation` array to `BlogPosting` schema for external grounding.

**Free tier (auto-extracted from post content, no UI):**
- In `wppugmill_output_singular_json_ld()`: scan `$post->post_content` for external `<a href>` tags; filter to off-domain links with meaningful anchor text; build `citation` array of `{@type: 'WebPage', name, url}` objects

**AI Connector tier (manual + AI-suggested, planned):**
- New `citations` array in `_wppugmill_aeo` meta
- Manual Citations panel in AEO editor
- `wppugmill_suggest_citations` AJAX action — AI identifies factual claims and suggests Wikipedia/authoritative URIs

---

## Pre-Submission Checklist (WordPress.org)

- [ ] Remove `WPPUGMILL_TEST_KEY` constant from `wp-pugmill.php` (line 31)
- [ ] Remove `WPPUGMILL_DEV_MODE` bypass from `wppugmill_mode()` in `wp-pugmill.php` (lines 44-47)
- [ ] Remove `define('WPPUGMILL_DEV_MODE', true)` from Local Sites `wp-config.php`
- [ ] Sync `WPPUGMILL_VERSION` constant (currently `0.5.2`) with plugin header (currently `0.5.3`)
- [ ] Update `readme.txt` stable tag from `0.4.6` to current version
- [ ] Confirm build is current (`npm run build`) — `build/index.js` matches `src/`

---

## Open Items / Known Limitations

- External image alt text: updates the block attribute in the editor but does not persist to the media library (image has no attachment ID)
- Classic Editor: "Write from Draft" shows reformatted body for manual paste instead of direct block injection
- `schemaAiNonce` and `howtoNonce` nonces are wired in `constants.js` but Schema AI features (Suggest Schema, Draft Steps) are AI Connector-only — enforced server-side

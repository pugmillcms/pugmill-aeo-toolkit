=== AEO Pugmill ===
Contributors: janzenworks
Tags: aeo, answer engine optimization, ai, structured data, bot analytics, llms-txt, schema, json-ld, yoast, rankmath
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The AEO plugin for WordPress. Structures your content for AI answer engines — works alongside Yoast, RankMath, and AIOSEO.

== Description ==

**AEO Pugmill** is the Answer Engine Optimization (AEO) plugin for WordPress. It focuses exclusively on what major SEO plugins don't cover — helping AI answer engines like ChatGPT, Perplexity, and Gemini understand, retrieve, and cite your content.

**Works alongside your existing SEO plugin.** AEO Pugmill detects Yoast SEO, RankMath, and AIOSEO and avoids duplicate output. It handles the AEO layer; your SEO plugin handles traditional SEO. Zero conflicts.

When someone asks ChatGPT, Perplexity, or Gemini a question your content answers — AEO Pugmill helps make sure they find and cite you.

= The Name =

In a ceramics studio, a pugmill is a machine potters use to reclaim clay. When clay gets overworked, mixed with too much water, or simply neglected, it becomes slop — raw, waterlogged, unusable. Potters call it slop for a reason. The pugmill takes that slop, compresses it, forces out the air, and extrudes it as wedged, ready-to-use clay.

Old-school SEO has the same problem. Years of keyword stuffing, thin content, and chasing blue links has left most sites full of digital slop — technically published, but not structured for how AI systems actually read and cite content. The information is in there. It's just not usable yet.

AEO Pugmill does for your content what a ceramic pugmill does for clay. It takes what you've already built — the good parts of your existing SEO — breaks it down, removes the air, and transforms it into structured, AI-ready signal that answer engines like ChatGPT, Perplexity, and Gemini can actually consume and cite.

De-aired. Wedged. Ready.

= What AEO Pugmill Does =

**Pugmill AEO Intelligence Network**
Your Bot Analytics data is anonymously compared against the broader network of Pugmill sites. See whether your AI crawler traffic is above or below average for each bot — and get context on whether your site is a typical crawl target or an outlier.

**llms.txt Generation**
Automatically generates `/llms.txt` and `/llms-full.txt` endpoints following the emerging llms.txt open standard. These files tell AI crawlers exactly what your site is about and where to find your best content.

**Per-Post AEO Metadata**
A dedicated editor on every post and page lets you add:
- AI-optimized summary (2-3 sentences for AI crawlers)
- Q&A pairs (generates FAQPage schema, helps AI cite specific answers)
- Named entity tags (people, organizations, products, concepts)
- Keywords (5-15 search-focused terms)

**JSON-LD Structured Data**
Automatically injects FAQPage schema on every post and page based on your AEO metadata — no configuration required. Article/BlogPosting schema is also output when no other SEO plugin is present; when Yoast, RankMath, or AIOSEO is active, Pugmill defers that node to them and focuses exclusively on the AEO-unique schema types.

**AEO Health Score**
A sidebar score (0-100) on every post shows exactly what AEO fields are complete and what's missing, with actionable tips for each gap.

**REST API Integration**
All AEO metadata is exposed on WordPress REST API responses, making your content AI-ready for headless and decoupled architectures.

= Three Modes =

**Free** — Full manual AEO toolkit. Install, fill in your AEO fields, and your content is immediately more AI-discoverable. No account required.

**AI Connector** — Activate with a license key from aeopugmill.com and connect your own Claude, GPT-4, or Gemini API key. Generate all AEO metadata for any post in one click, use **Write from Draft** to rewrite your rough draft into full Answer Unit structure, and distribute your content with AI-written social drafts, excerpt copy, and internal link suggestions.

**Pro** *(Coming Soon)* — AI generation powered by AEO Pugmill infrastructure. No API key needed. Includes bulk generation, site-wide AEO dashboard, and author voice training.

= External Services =

This plugin connects to the following external services:

**Pugmill AEO Intelligence Network** (anonymous bot traffic benchmarking)
When you opt in to Bot Analytics (Settings → AEO Pugmill → Bot Analytics), the plugin:
- Registers your site with a one-way hashed site ID (SHA-256 of your home URL + a randomly generated instance ID). Your URL is never transmitted directly.
- Submits anonymized daily bot traffic counts (bot name, resource type, visit count) to pugmillaeo.com once per day via a scheduled background task.
- Fetches aggregated network averages from pugmillaeo.com to show how your bot traffic compares to other sites on the network.

No visitor data, IP addresses, post content, or personally identifiable information is ever transmitted. You can opt out at any time from the Bot Analytics tab; opting out stops all submissions and removes your site from the network.
- Service: [https://pugmillaeo.com](https://pugmillaeo.com)
- Privacy Policy: [https://pugmillaeo.com/privacy](https://pugmillaeo.com/privacy)
- Terms of Service: [https://pugmillaeo.com/terms](https://pugmillaeo.com/terms)
- This connection only occurs after explicit opt-in. Free mode users who have not opted in make no connections to pugmillaeo.com.

**Pugmill License Server** (license validation)
When a license key is entered in Settings → AEO Pugmill, the plugin contacts the pugmillaeo.com API to validate the license. This sends your license key and your site's domain to the license server.
- Service: [https://pugmillaeo.com](https://pugmillaeo.com)
- Privacy Policy: [https://pugmillaeo.com/privacy](https://pugmillaeo.com/privacy)
- Terms of Service: [https://pugmillaeo.com/terms](https://pugmillaeo.com/terms)
- This connection only occurs when a license key is entered. Free mode users make no external connections.

**AI Providers** (AI Connector mode only)
When an AI API key is configured and either "Generate with AI" or "Write from Draft" is clicked by an admin, the plugin sends data to your chosen AI provider. No connection is made automatically — only on explicit admin action.

Data sent to the AI provider:
- Post title
- Post body text (stripped of HTML, truncated to 8,000 characters)
- For "Write from Draft" in the block editor: unsaved draft content from the current editing session may also be sent if it differs from the saved post content

No visitor data, user data, or personally identifiable information is ever transmitted to AI providers.

- Anthropic (Claude): [https://anthropic.com](https://anthropic.com) — [Privacy Policy](https://anthropic.com/privacy) — [Terms](https://anthropic.com/terms)
- OpenAI (GPT): [https://openai.com](https://openai.com) — [Privacy Policy](https://openai.com/privacy) — [Terms](https://openai.com/terms)
- Google (Gemini): [https://ai.google.dev](https://ai.google.dev) — [Privacy Policy](https://policies.google.com/privacy) — [Terms](https://policies.google.com/terms)

= Privacy =

- No visitor data, IP addresses, or personally identifiable information is collected or transmitted by this plugin
- Bot Analytics network participation requires explicit opt-in; no data is sent before consent is given
- Bot traffic data submitted to the network is anonymized — your site URL is never transmitted; only a one-way hash is used
- AI API keys are encrypted at rest using AES-256-CBC
- License keys are encrypted at rest using AES-256-CBC
- All external connections use HTTPS with SSL verification

== Installation ==

1. Upload the `aeo-pugmill` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Settings → AEO Pugmill** to configure
4. Edit any post or page — you will see the AEO metadata editor and health score
5. Visit `yoursite.com/llms.txt` to confirm the endpoint is working

= AI Connector Setup =

1. Purchase a license at [aeopugmill.com/pricing](https://aeopugmill.com/pricing)
2. Enter your license key in **Settings → AEO Pugmill**
3. Select your AI provider and enter your API key
4. Open any post and click **Generate with AI**

== Frequently Asked Questions ==

= What is a pugmill? =
In a ceramics studio, a pugmill is a machine that reclaims clay. When clay gets overworked or mixed with too much water it becomes slop — potters' shorthand for waterlogged, unusable material. The pugmill takes that slop, compresses it, forces out the air bubbles, and extrudes it as clean, wedged, ready-to-use clay. This plugin does the same thing to your content: takes the good parts of your existing SEO, removes the filler, and structures it into something AI engines can actually use.

= What is AEO? =
AEO (Answer Engine Optimization) is the practice of structuring your content so AI answer engines like ChatGPT, Perplexity, and Gemini can easily understand, cite, and surface it in response to user questions.

= What is llms.txt? =
llms.txt is an emerging open standard (similar to robots.txt) that helps AI systems discover and understand your site's content. AEO Pugmill automatically generates and maintains this file.

= Do I need an AI API key to use this plugin? =
No. The free version includes all manual AEO tools — the metadata editor, llms.txt, JSON-LD schema, health score, and REST API integration. An AI API key is only needed for the AI Connector (one-click generation) feature.

= Which AI providers are supported? =
Anthropic (Claude), OpenAI (GPT-4o), and Google (Gemini). You can use any of these with your own API key in AI Connector mode.

= Is my API key secure? =
Yes. API keys and license keys are encrypted at rest using AES-256-CBC encryption, keyed to your WordPress installation. They are stored server-side only and never exposed to site visitors.

= Will this slow down my site? =
No. AEO Pugmill adds zero frontend JavaScript. JSON-LD is generated server-side. The llms.txt endpoints are cached (1 hour TTL) and only hit by AI crawlers, not human visitors.

= Does this work with my existing SEO plugin? =
Yes — that's the point. AEO Pugmill detects Yoast SEO, RankMath, AIOSEO, and The SEO Framework. When one of these is active, the Compatibility tab shows you exactly which outputs overlap and lets you suppress Pugmill's duplicates with a single checkbox. Your SEO plugin owns SEO; Pugmill owns AEO. They do different jobs.

= What does AEO Pugmill add that my SEO plugin doesn't? =
Three things no major SEO plugin generates automatically:

1. **FAQPage JSON-LD** — AI-extracted Q&A pairs structured for rich results and AI answer engines
2. **Entity graph with sameAs links** — typed mentions (Person, Organization, Product, etc.) linking to Wikidata/Wikipedia for AI understanding
3. **Citation extraction** — external links from post content structured as schema.org citations, signalling credibility to AI systems

Plus: llms.txt, per-post AEO markdown endpoints, and a bot analytics dashboard showing exactly which AI crawlers are visiting your content.

== Screenshots ==

1. AEO metadata editor on the post edit screen
2. AEO Health Score sidebar widget
3. Settings page showing license and AI provider configuration
4. Example llms.txt output

== Changelog ==

= 1.0.1 =
* **Fix**: AI Provider form now saves API keys correctly — restored the JavaScript change-detection listener lost during dashboard consolidation.
* **Tests**: Updated PHP test suite — removed stale audit.php references, added on-page SEO store tests, bumped version constant. All 205 tests pass (65 PHP plugin + 23 PHP encryption + 117 JS).

= 1.0.0 =
* **Dashboard consolidation**: Settings page restructured from 8 tabs to 5. New Dashboard tab is the default, with three inline setup cards (AI Provider, Author Voice, Pro License) that expand on demand and collapse when configured. Bot Analytics content now lives directly in the Dashboard.
* **Data visualizations**: Content Reach diet bars, Crawl Intelligence quality bars, Top Posts visit bars, dot markers for network averages, and proportional bubble chart for Bot Activity.
* **Visual polish**: Consistent bar weights, solid track backgrounds, larger legends, title case headings throughout.
* First public release — prepared for WordPress.org submission.

= 1.3.4 =
* **AEO Content Coverage**: Network comparison redesigned — replaces the second parallel bar with a single bar plus a purple tick marker at the network average position and a clear "Network avg: X%" label. Opted-in sites now always see the comparison row (shows "—" when no network data yet, instead of hiding entirely).

= 1.3.3 =
* **Network**: All API calls to pugmillaeo.com now use the www canonical URL directly, eliminating a 307 redirect that was occurring on every registration, ingest, and report fetch. This resolves an edge case where the Authorization header could be dropped on redirect, causing a false 401 on registration.

= 1.3.2 =
* **AI Provider**: Save Changes button is now disabled until a provider is selected (required field). A hint message prompts the user to select a provider first, preventing silent failures from submitting a key with no provider set.
* **License**: When activating a license on a site that exceeds the plan's site limit, the error now reads "You've reached the X-site limit for this license. Remove it from another site first." instead of the generic "Invalid license key."

= 1.3.1 =
* **Audit AEO**: Missing field tags (Summary, Q&A, Entities, Keywords) are now clickable generate buttons for any user with an API key. Generated content is saved directly to post meta — no post editor visit required.
* **Audit AEO**: Generate All (Pro) also autosaves to post meta immediately. Status text simplified to "✓ Generated".
* **AI Provider**: Save Changes button is disabled on page load when a key is already stored; re-enables when the user types a new key, changes provider, or changes rate limit — prevents accidental masked-value submission.
* **Fix**: Doubled "AEO Pugmill" in Pro license strings corrected across settings page and AI content handlers.

= 1.3.0 =
* **Rename**: Plugin fully renamed from "WP Pugmill" to "AEO Pugmill" — all PHP functions, option keys, AJAX actions, meta keys, and JS constants updated to aeopugmill_ prefix. Clarifies the plugin's exclusive focus on Answer Engine Optimization.
* **Audit AEO**: Scores are now calculated exclusively via AJAX on page load — no stale stored values ever displayed. Fresh install now correctly shows pulsing placeholders until scores are computed.
* **AI Provider**: Replaced all bullet-character detection with a hidden field flag (aeopugmill_api_key_changed). JS sets it to "1" only when the user actually types a new key; PHP sanitize callback skips key processing when the flag is absent.
* **AI Provider**: Removed "Test Connection" button; API key validated on blur with a status indicator.

= 1.2.22 =
* **AI Provider**: Replaced all bullet-character detection with a hidden field flag (aeopugmill_api_key_changed). JS sets it to "1" only when the user actually types a new key; PHP sanitize callback skips key processing entirely when the flag is absent, guaranteeing the stored encrypted key is never accidentally overwritten with a garbled masked value.
* **AI Provider**: Field now shows a persistent "✓ Key saved" indicator on page load when a key exists. Indicator clears the moment the user starts typing, making the field state unambiguous.

= 1.2.21 =
* **AI Provider**: Removed the "Test Connection" button. API key is now validated automatically on blur (when the user leaves the field) and shows a status indicator before saving. Validation is also triggered on form submit when the key is dirty. Cleaner UX with no extra button to click.

= 1.2.20 =
* **AI Provider**: Fixed Test Connection returning "invalid key" when the field showed the saved masked value. JS now tracks whether the field is dirty (user typed a new key) and only sends the field value when dirty. When pristine, the AJAX request omits api_key entirely and PHP uses the stored encrypted key directly — no fragile bullet-character detection needed.

= 1.2.19 =
* **Audit AEO tab**: Stored scores are never rendered in the initial page HTML. Every row always shows a placeholder on load; AJAX calculates all scores fresh using the same PHP logic as the post editor sidebar and replaces the placeholders. Eliminates stale/incorrect scores from previous installs appearing in the UI.
* **Audit AEO tab**: Simplified query — removed aeo_json join since missing-field detection now happens exclusively in the AJAX response, not the PHP render.

= 1.2.18 =
* **Audit AEO tab**: Scores now recalculated fresh for every visible row on page load via AJAX — same algorithm as the post editor sidebar, so scores always match.
* **Audit AEO tab**: Default sort changed to newest-first (post date descending), matching the standard WordPress posts list. Score column remains sortable asc/desc.
* **Audit AEO tab**: Removed "opportunity" sort — simpler is better; date + score covers the real use cases.
* **Audit AEO tab**: Simplified query — removed content_score join since it is no longer used for sorting.

= 1.2.17 =
* **Bug fix**: Audit AEO tab showed "No published posts found." — ORDER BY clause referenced alias `total_score` but SELECT used `total_score_raw`, causing MySQL to silently return no rows. Fixed alias references in both sort branches.

= 1.2.16 =
* **Audit AEO tab**: Removed site-wide backfill button and yellow banner — score calculation now runs only for the posts visible on the current page.
* **Scoring**: Removed unused aeopugmill_get_unscored_batch AJAX handler.

= 1.2.15 =
* **Audit AEO tab**: Unscored rows now show pulsing grey placeholders on page load; scores are fetched via AJAX and populated in real time without a page reload.
* **Audit AEO tab**: "Calculate All Scores" backfill button processes all unscored posts in batches across the entire site, updating visible rows as they complete.
* **Scoring**: Added aeopugmill_calculate_scores AJAX handler — calculates health + content scores for a batch of post IDs and persists both meta values.
* **Scoring**: Added aeopugmill_get_unscored_batch AJAX handler — returns next batch of posts missing a stored score, with remaining count.

= 1.2.14 =
* **Scoring**: PHP health score rewritten to match JS sidebar scoring exactly — same 12 checks, same point values. Dropped SEO title/meta description; added content structure checks (400+ words, H2/H3, no H1, opening paragraph), keywords in content, and featured image alt text.
* **Scoring**: Added _aeopugmill_content_score meta (0–35) stored on save — tracks content-structure readiness for efficient Audit AEO sorting.
* **Scoring**: Score now recalculates on save_post (content changes) and featured image changes, in addition to AEO meta changes.
* **Audit AEO tab**: New tab between Site AEO and Bulk AEO — paginated table of all published posts ordered by content-readiness first.
* **Audit AEO tab**: Columns: Title, Type, Score pill (same colour scale as sidebar), Missing AEO Fields (Summary/Q&A/Entities/Keywords tags), Generate All.
* **Audit AEO tab**: Score column sortable asc/desc. Post type filter shown when multiple public post types exist. Sort and filter state preserved across pagination.
* **Audit AEO tab**: Generate All active for Pro/AI users from the table; locked with upgrade prompt for free users.
* **Uninstall**: Added _aeopugmill_content_score to post meta cleanup.

= 1.2.13 =
* **Bug fix**: Restored missing useSeoMeta hook in sidebar — its removal in 1.2.11 caused a JS runtime crash that hid the entire Pugmill sidebar panel. SEO panel UI remains removed; the hook is retained for Generate All and meta merge logic.

= 1.2.12 =
* **Posts list (edit.php)**: Removed AEO Score as a standalone column — avoids layout conflicts with Yoast/RankMath/AIOSEO columns. Score now appears as a small lavender pill inline after each post title, injected via JavaScript with no impact on column structure.

= 1.2.11 =
* **Sidebar**: Removed SEO panel (SERP preview, SEO title, meta description, canonical, robots, OG image) — Pugmill is AEO-focused and these fields overlap with Yoast/RankMath/AIOSEO. Existing saved SEO data continues to output correctly. Featured image alt text moved up under the AEO section.

= 1.2.10 =
* **Posts list (edit.php)**: Replaced min-width on Title column with white-space:nowrap on the AEO Score column header — fixes the root cause without touching WordPress-owned columns.

= 1.2.9 =
* **Posts list (edit.php)**: Fixed Title column collapsing to near-zero width — added min-width floor to prevent AEO Score column from squashing it.
* **Compatibility tab**: Checkbox label now reads "Let [plugin] handle this" when an SEO plugin is detected, instead of the generic "Disable".
* **Bot Analytics**: Added empty state banner explaining the dashboard fills with data as bots visit the site.

= 1.2.8 =
* **Settings — AI Provider**: Test Connection button moved inline to the right of the API Key field for faster access.
* **Settings — AI Provider**: Test Connection now tests the key currently typed in the field — no need to save first.
* **Settings**: Third-party admin notice banners (e.g. AIOSEO's MonsterInsights upsell) are now suppressed on AEO Pugmill's settings page.

= 1.2.7 =
* **Uninstall**: Fixed incomplete cleanup — added 5 missing options (disable_breadcrumbs, disable_robots_append, disable_sitemap, industry, signal_db_version), 3 missing transients (llms_txt_conflict_check, ai_analytics_insights, intel_site_meta), 1 missing post meta key (_aeopugmill_score), and the aeopugmill_signal_daily custom table. Plugin deletion now removes all plugin data from the WordPress database.

= 1.2.6 =
* **Bot Analytics — AEO Content Coverage**: Expanded from 4 bars to 8 — now includes Summary quality (50+ chars), Q&A Pairs (3+), SEO Title, and Meta Description alongside the existing AEO fields. Grouped into AEO Fields and SEO Fields sections.
* **Bot Analytics — AEO Content Coverage**: Card subtitle updated to reflect AEO and SEO coverage.
* **Compatibility tab**: Meta Description, Open Graph Tags, and Twitter / X Cards rows now share a single checkbox with rowspan grouping — visually connected without redundant controls.
* **Compatibility tab**: All conflict resolution instructions rewritten to lead with intent — "If you want AEO Pugmill to serve X, do Y" — and robots.txt conflicts now present both resolution options explicitly.
* **License tab — What's included**: Fixed "Generate SEO/AEO Title & Description" label to "Generate SEO Title & Meta Description". Tightened "Schema AI Type" row to "AI Schema Type Detection (Article, HowTo, Product, Event & more)".
* **Author Social Profiles**: Help text rewritten to clarify these URLs are not displayed on the site — they are embedded in JSON-LD Person schema as sameAs links to establish authorship identity for AEO.

= 1.2.5 =
* **Bug fix**: Network category trend arrows (↑/↓ % network) in the Bot Benchmark quadrant grid were never displaying — the API returns category keys like "AI Answer Engine" but the plugin was looking up "ai". Fixed with a remapping layer.
* **Network coverage**: Plugin now captures the `content_coverage` block from the network API response and uses it to show a "net avg" comparison bar beneath each AEO field bar in the AEO Content Coverage card.
* **AEO Content Coverage card**: Removed the radar/spider chart — replaced with taller horizontal bars that are directly comparable to the bar charts on pugmillaeo.com/intelligence. Adds a thin purple "net avg" bar beneath each field bar when network data is available.
* **Category labels**: Aligned category display names across the plugin and the public dashboard — "AI Crawlers" → "AI Answer Engines", "Training Crawlers" → "AI Training Crawlers", "SEO Bots" → "SEO Tools".
* **pugmillaeo.com**: Section order on /intelligence reordered — Bot Activity Trends now follows Categories immediately, then What Bots Are Fetching (categories → bots → resources → signals → coverage).
* **pugmillaeo.com**: Resource type distribution now grouped by taxonomy (AEO Endpoints / Discovery / Page Crawls) instead of sorted by volume — makes the AEO vs non-AEO split immediately visible.
* **pugmillaeo.com**: Category cards now render in canonical AI-first order (AI Answer Engines, AI Training Crawlers, Search Engines, SEO Tools) matching the plugin's quadrant layout.
* **pugmillaeo.com**: Signal Intelligence bar charts now use human-readable bucket labels (Short/Medium/Long, Fresh/Recent/Mature/Archive) instead of raw database keys.

= 1.2.4 =
* **Compatibility tab**: Removed "AEO-Exclusive Outputs" panel — this information is already communicated by the AEO Infrastructure card on the Bot Analytics page. Compatibility tab now focuses entirely on plugin coexistence.
* **Compatibility tab**: Added note listing the five SEO plugins Pugmill auto-detects (Yoast, Rank Math, AIOSEO, The SEO Framework, SEOPress) with guidance for users running unlisted plugins.
* **License tab**: Replaced marketing copy with a concise 4-step setup guide linking directly to the AI Provider, Author Voice, and Bot Analytics tabs.
* **Removed**: Import from Another SEO Plugin feature and migration.php — avoids positioning conflict with SEO plugin makers.
* **Network payload**: Fixed `pugmill_outputs_active` always sending `[]` due to a guard on a non-existent function; now uses `aeopugmill_active_outputs()` which reads actual option flags.
* **Network payload**: Added missing `pugmill_outputs_active` field to the manual "Send now" AJAX handler (was present in cron send only).
* **AI Insights**: Added `aeo_field_coverage`, `posts_total`, and `posts_with_aeo` to the analytics context so the AI analyst can identify content coverage gaps and recommend Bulk AEO Generation where appropriate.
* **Copy**: Various UX copy refinements — "AI-generated" → "AI-refined", flipped sentence order on AI Insights card, updated Bot Analytics Network opt-in description.
* **Bot Activity chart**: Removed "Bot Analytics" from the AEO-Exclusive Outputs list (it is a dashboard, not an HTTP endpoint).

= 1.2.3 =
* **Charts**: Last 30 Days bar chart top scale now rounds up to the nearest 1/2/5 × 10ⁿ — e.g. a raw peak of 6,486 becomes 7,000, giving intuitive midpoint and grid lines.

= 1.2.2 =
* **Labels**: Renamed "Citations" → "Citation JSON-LD" and "Article" → "Article JSON-LD" in the AEO Infrastructure feature list — clearer for AEO-savvy users who understand JSON-LD.
* **Bot Activity chart**: Donut diameter increased from 140px to 200px; ring radii scaled proportionally (inner 66/40, outer 88/69) for better visual impact.

= 1.2.1 =
* **Labels**: AEO Infrastructure feature list vocabulary aligned with post sidebar. "On-Page Meta" → "Meta Tags" (these are `<head>` elements, not visible page content). Structured Data items drop redundant "Schema" suffix and use sidebar words: Q&A Pairs, Named Entities, Article, Breadcrumbs. robots.txt moved from Meta Tags into AEO Endpoints alongside llms.txt. Slashes removed from llms.txt and llms-full.txt display names.
* **Charts**: Benchmark bar chart scale (`q_max`) now rounds up to the nearest 1/2/5 × 10ⁿ instead of using the raw peak — gives intuitive axis breaks (e.g. 1,423 visits → scale to 2,000 not 1,423).
* **Fix**: "Bot Analytics" removed from the AEO Endpoints feature list in the Infrastructure column — it is the analytics dashboard, not an HTTP endpoint.
* **Fix**: Structured Data items renamed to match post sidebar vocabulary: "FAQPage Schema" → "Q&A Schema", "Entity Graph" → "Named Entity Schema".
* **Accessibility**: Legend changed from color-only "Purple = network average" to `Purple "avg" bar = network average`, pairing the visual color with the text label shown in the chart.
* **Redesign**: Bot benchmark grid replaces the verbose 4-quadrant layout — lead story is "your site vs. network average". Category descriptions and large visit counts removed (visible in summary row above). Cards are ~40% shorter.
* **UX**: CSS variables per benchmark card for color theming; `aria-*` meter attributes on benchmark bars.

= 1.1.8 =
* **Redesign**: Bot Analytics summary row is now a 3-equal-column layout: Bot Activity (two-ring donut + full grouped bot legend with all individual bots listed under their category), AEO Content Coverage (radar chart + 4 progress bars with counts), and AEO Infrastructure (semi-gauge + full feature list grouped by AEO Endpoints / Structured Data / On-Page Meta with Pugmill/SEO/Off badges).
* **Fix**: Bot Activity legend now shows every individual bot (not just categories) with its lighter-shade color dot, visit count, and category grouping.
* **UX**: Radar canvas reduced to 200px for balanced column proportions; gauge enlarged to 140×80px for readability.

= 1.1.1 =
* **Fix**: `associatedMedia` (markdown endpoint link) moved from Article/BlogPosting node to FAQPage node. The Article node is suppressed in Yoast coexistence mode, which was silently hiding the markdown link from AI crawlers. FAQPage always outputs, so the link is now reliably discoverable.
* **New**: Resource type 7 “AEO Post” — bot visits to singular posts with AEO data (FAQPage, entities, or summary) are now tracked separately from generic HTML crawls. Adds a second phase at `template_redirect` to check post meta after WordPress resolves the queried object.
* **New**: Network ingest payload upgraded to schema v3 — now includes `seo_plugin`, `posts_with_aeo`, `posts_total`, `markdown_assets_served`, and `pugmill_outputs_active` for richer network intelligence.
* **Fix**: AI Provider dropdown now shows “— Select provider —” when no API key is stored, rather than preselecting the previously saved provider after a key is removed.
* **Remove**: “Get steps” AI buttons removed from Compatibility tab conflict items. The default informational message remains.
* **Copy**: AEO-Exclusive Outputs description in the Compatibility tab rephrased to focus on value rather than competitive claims.
* **Housekeeping**: Parsedown MIT license attribution added to bundled vendor file.

= 1.1.0 =
* **Repositioning**: AEO Pugmill is now positioned as an AEO plugin, not an SEO plugin. Works alongside Yoast, RankMath, AIOSEO, and The SEO Framework — not against them.
* **New**: Compatibility tab redesigned with three sections: SEO Plugin Status (live detection), AEO-Exclusive Outputs (always-on read-only list), and Overlapping SEO Outputs (per-output toggles).
* **New**: Detects active SEO plugins via runtime constants (Yoast, RankMath, AIOSEO, The SEO Framework, SEOPress).
* **New**: Per-output disable toggles — suppress meta description, Open Graph, Twitter Cards, Article JSON-LD, and Breadcrumb Schema independently.
* **Fix**: `aeopugmill_disable_seo_meta` option is now actually applied to meta tag output (was registered but not checked in previous releases).
* **Change**: Disabling JSON-LD no longer suppresses FAQPage schema — FAQPage is AEO-exclusive and always output.
* **New**: Breadcrumb schema has its own independent disable toggle (`aeopugmill_disable_breadcrumbs`).
* **New**: `associatedMedia` property added to Article/BlogPosting JSON-LD when AEO content exists — links to the per-post `?aeopugmill_llm=1` markdown endpoint. (Note: moved to FAQPage node in 1.1.1 for Yoast coexistence compatibility.)
* **Change**: Plugin description updated for AEO identity.

= 1.0.46 =
* **New**: Bot Analytics tab now shows three donut charts — Bot Categories, AEO Adoption, and Top Crawlers — above the 30-day trend graph.
* **Change**: Merged Sitemap & Robots tab into Sitemap & Compatibility tab.
* **Fix**: "Send to Network" button now only appears when the user has opted into the Pugmill AEO Intelligence Network.

= 1.0.43 =
* **Change**: Removed hardcoded test license key — all license validation now goes through the pugmillaeo.com API.
* **Change**: Added "Manage subscription" link to the License tab next to the renewal date, pointing to the Stripe Customer Portal.

= 1.0.42 =
* **Fix**: Fatal error on activation caused by duplicate `aeopugmill_mode()` function definition — removed redundant declaration from `license.php` (canonical version with dev-mode bypass lives in `aeo-pugmill.php`).

= 1.0.41 =
* **Change**: License validation migrated from Lemon Squeezy to self-hosted Stripe-backed system at pugmillaeo.com. Domain registration now happens passively on first validation — no separate activation call required. Supports up to 3 sites per license key.


= 1.0.40 =
* **Fix**: Jetpack robots.txt conflict warning now correctly gates on the XML Sitemaps module being active (same guard used by the sitemap conflict check). Previously it fired for any active Jetpack install regardless of whether sitemaps were on.
* **Copy**: Clarified Jetpack conflict instructions to reference "XML Sitemaps" in Jetpack → Settings → Traffic rather than the vague "Sitemap option".


= 1.0.39 =
* **UI**: Bulk AEO layout — Priority, Request Delay, and Batch Size moved to a shared second row, separated from Content and Options by a divider.


= 1.0.38 =
* **Feature**: Bulk AEO batch size control — choose 50, 100, 250, 500, or All posts per run. Defaults to 100. The start button label updates to reflect the selection (e.g. "Generate AEO for Next 100 Posts"). Run again to continue processing the next batch.


= 1.0.37 =
* **Branding**: Renamed "Pugmill Intelligence Network" → "Pugmill AEO Intelligence Network" throughout the plugin UI, prompts, and code comments.

= 1.0.36 =
* **Enhancement**: Get AI Analytics now includes per-bot Crawl Intelligence signals (word count, freshness, fact density, URL depth, URL type) from this site in the analysis context, plus network-average signals from pugmillaeo.com for comparison. New "## Crawl Intelligence" section in the AI report interprets what bots are reading and how it compares to the network.

= 1.0.35 =
* **Change**: Network endpoint updated from pugmill.dev to pugmillaeo.com.

= 1.0.34 =
* **Feature**: Per-bot Crawl Intelligence signals now included in the daily network payload — word count, content freshness, fact density, URL depth, URL type, 404 rate, and generation time sent to pugmillaeo.com keyed by bot name.

= 1.0.33 =
* **Enhancement**: Unknown bot display names — extract the domain from any URL embedded in the UA string (e.g. "ahrefs.com", "semrush.com") instead of raw UA fragments. Falls back to the leading token (e.g. "curl", "python-requests"), then silently drops truly unidentifiable UAs.

= 1.0.32 =
* **Enhancement**: Content Reach and Crawl Intelligence tables — vertical divider after the Bot column and before the Total column for consistent visual separation across both tables.

= 1.0.31 =
* **Enhancement**: Crawl Intelligence table — vertical divider after the Bot column, matching the group dividers. Bot header cell bottom border now matches the rest of the table.

= 1.0.30 =
* **Enhancement**: Crawl Intelligence table — vertical divider lines between Content Quality, Crawl Behavior, and Performance column groups for clearer visual separation. Fixed "Behaviour" → "Behavior" (American English).

= 1.0.29 =
* **Fix**: Critical error on Bot Analytics page — $intel_signals was never assigned, causing a PHP 8 TypeError (array_filter on null) that killed the page after Content Reach rendered.

= 1.0.28 =
* **Fix**: Critical error on Bot Analytics page — $active_types, $cat_labels, and $cat_badge were undefined after the Content Reach table refactor; added function_exists() guard on aeopugmill_intel_get_signals_30d().

= 1.0.27 =
* **Feature**: Content Reach table flipped — bots now as rows, content types as columns with category group headers (AEO Endpoints / Discovery / Page Crawls). Scales cleanly as the bot list grows.
* **Feature**: New Crawl Intelligence table — shows per-bot behavioral signals: dominant word count, content freshness, fact density, URL depth, URL type, 404 rate, and average PHP generation time.

= 1.0.26 =
* **Internal**: Bot signal capture upgraded to store signals per-bot (schema v2). Enables the upcoming Crawl Intelligence UI table.

= 1.0.25 =
* **Feature**: GitHub-based auto-update re-enabled (Plugin Update Checker). Sites running AEO Pugmill will now receive update notifications in WP Admin → Plugins automatically.
* **Feature**: Bot signal capture — anonymised content signals (word count, content freshness, fact density, etc.) are now captured server-side and submitted to the Pugmill AEO Intelligence Network daily.

= 1.0.24 =
* **Feature**: AI-powered "Get steps" button on each plugin conflict in the Compatibility tab — gives step-by-step instructions for resolving the conflict using the configured AI provider.
* **Fix**: Removed false-positive WordPress core sitemap conflict (core serves /wp-sitemap.xml, not /sitemap.xml — no real conflict).

= 1.0.23 =
* **Fix**: Reorder Bot Analytics quadrant grid — AI Crawlers (top-left), Search Engines (top-right), SEO Bots (bottom-left), Training Crawlers (bottom-right). Mobile view stacks in the same order.
* **Fix**: Removed Plugin Update Checker (GitHub-based auto-update) in preparation for WordPress.org directory submission.
* **Docs**: Added Pugmill AEO Intelligence Network to External Services disclosure in readme.txt.
* **Code**: Clarified that AEOPUGMILL_NETWORK_SECRET is a public protocol version identifier, not a private secret.

= 1.0.22 =
* **UI**: Quadrant category description text now spans full block width.

= 1.0.21 =
* **UX**: Bot Analytics quadrant headers now include a one-line description of each category so users understand the difference between AI Crawlers, Training Crawlers, Search Engines, and SEO Bots.

= 1.0.20 =
* **UI**: Bot Analytics quadrant bars now equal height (6px each); "you" and "avg" labels added to the left of each bar row.

= 1.0.19 =
* **Redesign**: Bot Analytics tab now uses a 2×2 quadrant layout — AI Crawlers, Training Crawlers, Search Engines, and SEO Bots each get their own panel with a sorted bot list and proportional bars.
* **Feature**: Each bot row shows a thick bar (your site) and a thin purple bar (network average) on a shared scale, so you can see at a glance whether you're above or below the network.
* **Fix**: SEO bots (Semrush, Ahrefs, etc.) now appear in their own dedicated quadrant instead of being excluded from the top-level view.
* **UX**: Network trend (% change vs prior 30 days) moves inline to each quadrant header. Legend at bottom notes the 30-day window and network average bar.

= 1.0.18 =
* **Enhancement**: Bulk AEO now processes posts one at a time with a configurable pause between requests — prevents server overload and stays within AI provider rate limits.
* **Feature**: New "Priority" sort in Bulk AEO — choose Newest first (default), Most commented first, or Oldest first so your highest-traffic content gets AEO data first.
* **UX**: Bulk AEO page now explains how processing works (sequential with delays) and prompts users to check their AI provider spend before running on large sites.

= 1.0.17 =
* **Fix**: Bulk AEO queue builder now uses a single JOIN query instead of one `get_post_meta()` call per post — eliminates N+1 DB queries that caused timeouts on large sites (1000+ posts).

= 1.0.16 =
* **Feature**: Bot Analytics tab now shows a "Network Trends" strip with four category cards (AI Crawlers, Training, Search, SEO Bots) sourced from the Pugmill AEO Intelligence Network — displays network-wide visit totals and % change vs prior 30 days for each category.
* **Enhancement**: Bot Analytics expanded from 12 to 25 known bots across four categories: AI companies (ChatGPT, Claude, Perplexity, Gemini, Amazonbot, Meta, Mistral), training crawlers (Bytespider, Cohere, DeepSeek, Grok, CCBot), traditional search engines (Googlebot, GoogleOther, Bingbot, YandexBot, BaiduBot, Applebot, DuckDuckGo), and commercial SEO bots (Semrush, Ahrefs, Dotbot, Majestic, Barkrowler, AI2Bot).
* **Feature**: Unknown bot catch-all — any unrecognized bot-like User-Agent is now detected, logged under "Other," and included in the recent activity feed with its parsed name.
* **Fix**: Bot Analytics tab no longer shows zero-visit bot cards — only bots with actual recorded visits appear.

= 1.0.14 =
* **Feature**: Plugin Compatibility tab now shows a side-by-side file comparison for sitemap.xml, llms.txt, and robots.txt — each column loads the live URL output and shows a AEO Pugmill preview, with radio buttons to choose which plugin handles each file.
* **Feature**: New options to disable AEO Pugmill's sitemap.xml generator and robots.txt additions independently (defers to other plugins like Jetpack, Yoast, or Rank Math).
* **Enhancement**: Compatibility checker now detects sitemap and robots.txt conflicts from Jetpack, Yoast SEO, Rank Math, AIOSEO, Google XML Sitemaps, and XML Sitemap & Google News.

= 1.0.13 =
* **Bug fix**: AEO Health fix buttons now auto-expand the corresponding lower panel (AI Summary, Q&A Pairs, Named Entities, Keywords) after generation so the user can see and save the result.
* **Bug fix**: "Keywords found in content" no longer shows a broken fix button — it now redirects to the Keyword Coverage tool in Topic Audit where the actual content rewriting happens.
* **UX**: Health fix "Done" state now reads "Applied — save post to keep" to prompt the user to save.

= 1.0.12 =
* **Bug fix**: "Keywords found in content" fix button now correctly shows as inactive for non-Pro users instead of showing as clickable and then throwing a license error.
* **UI**: Generate All button now shows a "Available with AEO Pugmill Pro" note for non-Pro users.
* **Fix**: Pro license error message no longer duplicates "AEO Pugmill" in the text.

= 1.0.11 =
* **Bug fix**: Sidebar resize handle no longer sticks when the mouse leaves the browser window mid-drag.

= 1.0.10 =
* **Enhancement**: Bulk AEO rate display uses "AI calls/hr" to match sidebar terminology.

= 1.0.9 =
* **Enhancement**: Bulk AEO tab — Bot Analytics moved to far-right tab position.
* **Enhancement**: Generate button now uses purple pill style matching all other AI action buttons; barber-pole animation plays during an active run.
* **Enhancement**: Progress card is now full-width (no max-width constraint).
* **Enhancement**: Speed throttle control — choose Fast (1.5s), Normal (3s), or Careful (6s) delay between posts to manage token spend rate.
* **Enhancement**: Live calls/hr rate displayed during a run.
* **Enhancement**: Intro text now includes a token-usage advisory for large sites.
* **Enhancement**: Browser warns before leaving the page while a run is in progress.

= 1.0.8 =
* **Feature**: Bulk AEO Generator — new Settings tab (Pro) generates AEO metadata for all published posts and pages in one run. Processes sequentially with pause/resume/cancel controls and live progress.

= 1.0.7 =
* **Bug fix**: Generate All no longer reverts the AEO health score after completing — a stale closure in the schema suggestion step was overwriting the freshly-generated AEO and SEO metadata.
* **Fix**: `saveIfDirty` now ignores Gutenberg autosave state transitions so it correctly resolves only against the explicit post save, preventing a potential race condition during Generate All.

= 1.0.6 =
* **Debug**: Registration errors now surface the actual HTTP response in the "Send now" button for easier diagnosis.

= 1.0.5 =
* **Feature**: Pugmill AEO Intelligence Network authentication — daily submissions are now signed with a per-site HMAC token obtained at opt-in registration. Prevents spoofed data from reaching the network.
* **UX**: "Send now" button on the Analytics tab lets admins manually trigger an intelligence submission and see the server response — useful for testing without waiting for cron.
* **Fix**: Pressable/managed-host cron reliability — registration happens immediately at opt-in and is retried automatically on the next cron run if the token is missing.

= 1.0.4 =
* **Bug fix**: AEO Health score ring now updates immediately when using inline Generate buttons — previously the score stayed stale until the WordPress data store propagated asynchronously.
* **Bug fix**: AEO score column on edit.php now self-heals stale cached values (e.g. a cached 100 after fields were removed) on the next page view.
* **UX**: Confirmation dialog on "Leave network" warns that Bot Analytics will also be disabled.

= 1.0.3 =
* **Bug fix**: Analytics opt-in state no longer resets when saving other settings (AI API key, SEO options, etc.). The opt-in was stored in the same WordPress settings group as all other plugin options, so any unrelated form save would wipe it to 0. It is now registered under its own group.

= 1.0.0 =
* **UX**: Anchor-not-found messages on Tone Check, Topic Focus, and Internal Links now explain that the passage was likely edited after the check ran, rather than implying a system error. Includes a prompt to re-run for fresh results.

= 0.9.9 =
* **Draft content**: Tone Check and Generate All → Internal Links now send the current editor draft directly to the AI without requiring a save first. All AI features now operate on the live editor state — no save step required at any point.
* **PHP**: Tone Check handler now prefers `draft_content` from the POST body over the saved database version, matching the behaviour of all other AI handlers.

= 0.9.8 =
* **Internal Links**: Fixed "AI returned an unexpected response format" error caused by the AI occasionally returning markdown-fenced JSON. Paragraph validation now uses the current draft content rather than the last saved version.
* **Draft content**: All `ajaxFetch` panel operations (Internal Links, Topic Focus, Reading Level, Headline Variants, Excerpt, Social Draft) now send current editor content directly — no pre-save required. Eliminates "Post has no content" race condition on unsaved drafts.
* **UX**: Added "Finish editing your content before generating" hint below the Generate All button. Topic Focus action button renamed from "Swap Content" to "✏ Rewrite".
* **Bot Analytics**: Network comparison arrows (↑/↓) in the Content Reach table indicate whether your per-bot traffic ratio is above or below the Pugmill network average. Chart legend centred. Top Posts URLs now link to the live post and include an inline Edit link. Download Data block repositioned after the Recent Visits section.
* **AI Insights**: Five enhancements — AEO conversion rate (AEO endpoint hits as % of total crawl), 15-day traffic trend with per-bot direction and change %, Pugmill AEO Intelligence Network benchmark with above/below-average signals, zero-visit gap callout for bots crawling other sites but not yours, and richer system prompt context for more actionable recommendations. Max tokens increased to 750.
* **Bot Analytics dashboard**: Replaced "All-time visits" card with per-category 30-day summary cards (AI Crawlers and Search Spiders) showing visit totals and percentage split. New symmetric 7-box layout: one summary card plus six per-bot cards per row.

= 0.9.0 =
* **Social Media Draft**: New AI Connector feature generates platform-optimised social copy for LinkedIn (700 chars), X/Twitter (280), Facebook (500), and Substack Notes (300). Uses AEO metadata as the primary signal. Hard-limit backstop trims to word boundary and appends ellipsis if the AI still exceeds the limit.
* **Excerpt Generator**: New AI Connector feature generates a compelling 1–2 sentence excerpt (max 160 chars) from post title and content.
* **Distribution tab**: Social Draft and Excerpt Generator are grouped in a new Distribution section of the sidebar panel.
* **Internal Links**: Suggests 3–5 internal linking opportunities with verbatim anchor text, target URL, and surrounding context. "Insert" wraps the anchor directly in the Gutenberg block. Server-side paragraph validation ensures anchors are placed only where the exact text exists.

= 0.8.0 =
* **Pugmill AEO Intelligence Network**: Bot Analytics now fetches anonymised per-bot, per-resource-type averages from the Pugmill network and compares your site's ratios. Network averages power the Content Reach comparison table and the AI Insights benchmark section.
* **AI Insights**: Refactored into a structured five-section report: Bot Activity, Traffic Trend, Network Benchmark, Content Coverage, and Recommendations.
* **Review schema**: Added Review as an extended schema type. Supports item type (Book, Movie, Product, Software, Course, Game, Music, Restaurant), item name, author/creator, star rating, and review body. Outputs a valid Review + Rating node in JSON-LD, eligible for Google rich snippets.
* **sameAs on entity mentions**: Each Named Entity now has an optional sameAs URL field (Wikipedia/Wikidata only — server-validated). When set, the JSON-LD `mentions` node includes a `sameAs` property for knowledge-graph disambiguation.

= 0.7.1 =
* **Stability**: Internal stability and error-handling improvements.

= 0.6.8 =
* **Cleanup**: Remove orphaned AuditPanel.jsx component file.
* **Code quality**: Fix British-English spellings in comments and test strings (`colour` → `color`, `optimisation` → `optimization`).

= 0.6.2 =
* **Loading spinners**: Refine Focus, Swap Content, and Bot Analytics "Get AI Analysis" buttons now show a spinner animation during AI calls — consistent with the Generate All button.
* **OG image / featured image integration**: The Open Graph Image field in the SEO panel now shows a thumbnail preview when a URL is set, a "Set as OG image URL" prompt when the featured image is available, and an amber nudge when neither is set.
* **AI Provider setup guide**: Settings → AI Provider now shows three provider cards (Anthropic, OpenAI, Google Gemini) with descriptions and direct links to each API key console, plus a numbered setup guide.
* **llms.txt score explanation**: The llms.txt Quality Score card now includes a point-value breakdown and an explanation of why the score matters. An AI "Get Improvement Tips" button (AI mode) sends your score breakdown to the AI and returns prioritized action steps.
* **Brand narrative**: Plugin description, readme, and Settings → License tab now tell the pugmill story — how a ceramic pugmill turns slop into de-aired, wedged, ready-to-use clay, and how this plugin does the same for your SEO content.
* **Schema panel tick**: The Schema panel title now shows a green ✓ when a schema type is selected.
* **Tone Check and Internal Links**: Various reliability improvements to content swap matching and panel layout.

= 0.6.1 =
* **llms.txt Quality Score**: New score card on Settings → Site AEO shows a 0–100 quality score for your llms.txt output. Tracks site-level completeness (site summary, organisation name) and per-post AEO coverage (summaries, Q&A pairs, keywords, entities) with colour-coded progress bars.
* **Bot-specific AI insights**: The Bot Analytics AI report now tailors its Recommendations section to the bots actually present in your traffic — ClaudeBot activity triggers sitemap advice, ChatGPT triggers llms.txt enrichment advice, Perplexity triggers Q&A prioritisation advice, and search-bot-only traffic triggers AEO onboarding advice.

= 0.6.0 =
* **AEO endpoint discovery in llms.txt**: Every post entry in `/llms.txt` and `/llms-full.txt` now includes a `Markdown:` line pointing directly to the post's `?aeopugmill_llm=1` optimised endpoint. AI crawlers that read llms.txt (ChatGPT confirmed, others emerging) now have an explicit map to every structured content endpoint — no inference from HTML headers required.
* **Sitemap alternate link annotations**: `/sitemap.xml` now includes `<xhtml:link rel="alternate" type="text/markdown">` inside every post and page `<url>` block. AI crawlers in structured discovery mode (ClaudeBot confirmed) can follow these directly to the AEO markdown endpoints rather than parsing raw HTML.

= 0.5.7 =
* **Bot Analytics structured report**: AI Insights analysis now returns a structured report with four labeled sections (Bot Activity, Content Coverage, AI vs Search Bots, Recommendations) rendered as scannable subheadings in the dashboard.

= 0.5.6 =
* **Schema type label**: The default schema type in the Schema panel now shows "Article" instead of "— None (Article only) —" for clarity.

= 0.5.5 =
* **AI-suggested sameAs URLs**: Entity extraction now asks the AI to return a Wikidata or Wikipedia `same_as` URL for well-known public entities when it is highly confident. Blank when uncertain — no guesses. Server-side validation accepts only `*.wikidata.org` and `*.wikipedia.org` URLs, preventing hallucinated links from reaching JSON-LD output.

= 0.5.4 =
* **Review schema**: New "Review" schema type in the Schema panel — set item type (Book, Movie, Product, Software, Course, Game, Music, Restaurant), item name, author/creator, star rating, and review body. Outputs a valid Review + Rating node in JSON-LD, eligible for Google rich snippets.
* **Citation auto-extraction**: BlogPosting schema now automatically includes a `citation` array populated from external links in the post content, improving AI engine trust signals and knowledge graph connectivity.
* **sameAs on entity mentions**: Each Named Entity in the AEO panel now has an optional "sameAs URL" field. When set, the JSON-LD `mentions` node includes a `sameAs` property pointing to the canonical knowledge-graph URL (Wikipedia, Wikidata, etc.), disambiguating entities for AI parsers.
* **Unit tests**: Added Jest test suites covering schema merge logic, AEO entity parsing (including sameAs), SEO meta merging, and all new constants (Review type options, descriptions, defaults).

= 0.4.6 =
* **UI consistency**: All panel action buttons standardized to purple pill style with ✓ Applied confirmation states (Tone, Headline Variants, Excerpt, Internal Links, Audit fixes)
* **AI Summary closed by default**: Summary panel in the AEO editor now starts collapsed
* **LICENSE file**: Added GPL-2.0 LICENSE file for WordPress.org compatibility
* **Clean distribution build**: Build script now correctly excludes dev files (src/, tests/, node_modules/, requirements.md) from the distributed zip

= 0.4.4 =
* **Auto-save before AI reads content**: Audit, Internal Links, Reading Level, Headline Variants, Topic Focus, Tone Check, and Social Draft all pre-save the post so AI always operates on current content
* **Auto-save after AI writes to editor**: Tone Fix, passage swap (Topic Focus), Insert Link, Rewrite from Draft, Simplify Draft, Use this Title, Apply to Excerpt, Apply Keyword Fix, and Insert Heading all save after applying changes
* **Alt text for external/inline images**: Generate Alt Text now works on images that aren't in the media library — passes the image URL directly to the vision API and applies the result via the block attribute
* **HTTP image support for Anthropic vision**: Images on HTTP (non-HTTPS) URLs are fetched server-side and base64-encoded before sending to Anthropic, resolving 400 errors on local and staging sites

= 0.4.3 =
* **Generate Alt Text**: New vision-API feature in the Audit panel generates descriptive alt text for the post's featured or first block image and saves it to the media library
* **Audit: has_thumbnail field**: Audit results now include `has_thumbnail` to distinguish "no featured image" from "image present but no alt text", enabling the Generate Alt Text button to show only when relevant

= 0.4.2 =
* **SEO Generate fix**: Resolved network error caused by undefined post content variable; now correctly reads current editor content including unsaved changes
* **Simplify Draft**: New AI Connector feature rewrites the post body at a simpler reading level with preview before applying

= 0.4.1 =
* Internal stability and error-handling improvements following 0.4.0 release

= 0.4.0 =
* **Topic Focus → Refine Focus**: AI-powered per-passage rewrite suggestions now use per-block validation matching Gutenberg's block structure, eliminating "passage not found" errors
* **SEO Generate** button fixed — was throwing a network error due to undefined post content variable; now correctly reads the current editor content including unsaved changes
* **User-selectable AI rate limit**: hourly call cap is now configurable in Settings → AEO Pugmill → AI Provider (50 / 100 / 200 calls per hour); default remains 50
* **Bot Analytics** page and dashboard tracking for AI crawler visits
* **SEO Audit** sidebar with on-page SEO scoring and recommendations
* **SEO Agent** for automated multi-step AEO improvements
* AES-256-CBC encryption for API keys and license keys at rest
* Full on-page SEO: title tags, meta description, canonical URL, robots meta
* XML sitemap with IndexNow ping support
* robots.txt customization

= 0.3.0 =
* **Topic Focus**: AI analysis identifies weak AEO passages in post content and suggests targeted rewrites; Swap Content replaces selected passages directly in the Gutenberg editor
* **Write from Draft** improvements: more reliable content matching across entity-encoded and smart-quote variants
* **Bot Analytics**: tracks AI crawler visits (GPTBot, ClaudeBot, PerplexityBot, Googlebot) with per-bot counts and trend data
* **AI Provider selection**: choose between Anthropic Claude, OpenAI GPT-4o, or Google Gemini in settings
* IndexNow integration — pings search engines on post publish/update
* Canonical URL and robots meta tag injection
* XML sitemap (posts, pages, categories, tags)
* Rate limiting on AI generation (50 requests/hour/user)

= 0.2.0 =
* **Write from Draft** (AI Connector): New feature rewrites a rough draft into Answer Unit structure (Primary Question → Direct Answer → Context). Populates AEO fields and updates post content in the Gutenberg block editor; shows reformatted body for manual paste in the Classic Editor.
* Per-post `?aeopugmill_llm=1` markdown endpoint for AI crawlers
* `<link rel="alternate" type="text/markdown">` Invisible Handshake header on every post
* Twitter Card meta tags (twitter:card, twitter:title, twitter:description, twitter:image)
* `DefinedTerm` entity type for custom vocabulary/glossary entries
* `image` and `publisher` properties in Article JSON-LD schema
* Open Graph meta tags (og:title, og:description, og:image, og:type)
* Meta description injection from AEO summary

= 0.1.0 =
* Initial release
* AEO metadata editor (summary, Q&A pairs, entities, keywords)
* llms.txt and llms-full.txt endpoint generation (cached, paginated)
* JSON-LD Article and FAQPage schema injection
* AEO Health Score (0-100) with per-field checklist
* REST API AEO field exposure
* Site-level AEO settings (organization schema, site summary)
* AI generation via Anthropic, OpenAI, or Google Gemini (AI Connector)
* License validation via Lemon Squeezy
* AES-256-CBC encryption for API keys and license keys at rest
* Rate limiting on AI generation (20 requests/hour/user)

== Upgrade Notice ==

= 1.1.1 =
Fixes markdown endpoint discoverability in Yoast coexistence mode. Adds AEO Post bot tracking and schema v3 network payload. Safe to upgrade — no database changes.

= 1.1.0 =
Major repositioning as an AEO plugin. Adds SEO plugin compatibility detection and UI. Safe to upgrade — no database changes.

= 0.4.6 =
UI polish and build improvements. Safe to upgrade — no database changes.

= 0.4.4 =
Adds auto-save before and after all AI operations. Fixes alt text generation for external images and HTTP image URLs with Anthropic. Safe to upgrade — no database changes.

= 0.4.0 =
Fixes Topic Focus passage-swap errors and SEO Generate network error. Adds configurable AI rate limit in Settings. Safe to upgrade — no database changes.

= 0.3.0 =
Adds Topic Focus AI rewrite, Bot Analytics, and IndexNow. Safe to upgrade — creates one new database table for bot analytics.

= 0.2.0 =
Adds Write from Draft AI feature and Twitter/Open Graph meta tags. Safe to upgrade — no database changes.

= 0.1.0 =
Initial release.

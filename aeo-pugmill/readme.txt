=== AEO Pugmill ===
Contributors: janzenms
Tags: aeo, answer engine optimization, ai, structured data, bot analytics
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AEO metadata, bot analytics, and llms.txt for WordPress. Tracks AI crawler visits and structures content for AI answer engines.

== Description ==

**AEO Pugmill** adds Answer Engine Optimization (AEO) infrastructure to WordPress. It structures your content for AI answer engines, tracks which AI crawlers are visiting your site, and — if you opt in — contributes anonymized daily counts to the Pugmill Network, where that data powers the crawler intelligence dashboard at aeopugmill.com.

The two features are independent. Bot analytics, AEO outputs, and llms.txt are all available without ever contributing to the network.

= The Name =

In a ceramics studio, a pugmill reclaims overworked clay — compressing it, forcing out the air, and extruding it as wedged, ready-to-use material. This plugin does the same for content: takes what is already published, removes the filler, and structures it into signal that AI answer engines can read and cite.

= What AEO Pugmill Does =

**Bot analytics**
On every incoming request, the plugin checks the user-agent against a list of known AI crawlers and search bots. When a match is found, it records the bot name, resource type, and date — stored locally in a daily summary table. The WordPress admin shows which bots are visiting, what they are fetching, and how counts trend over time.

**Pugmill AEO Intelligence Network**
Opting in sends one small daily payload to aeopugmill.com: bot visit counts by bot name and resource type, content signal distributions (word count, freshness, fact density, URL depth), and a one-way SHA-256 hash of the site URL. No domain, no content, no URLs, no user data are transmitted. That aggregate is what powers the crawler intelligence dashboard. Participation is opt-in and can be disabled at any time from the Bot Analytics tab.

**llms.txt generation**
Automatically generates `/llms.txt` and `/llms-full.txt` — structured content indexes following the llms.txt open standard. AI crawlers use these to discover what the site covers and where to find content in full.

**Per-post AEO metadata**
A dedicated editor on every post and page for:

- An AI-optimized summary (2–3 sentences structured for AI retrieval)
- Q&A pairs (generates FAQPage JSON-LD schema)
- Named entity tags with typed mentions (Person, Organization, Product, Concept)
- Keywords

**JSON-LD structured data**
Injects FAQPage schema, entity mentions with sameAs links, and citation extraction into every post with AEO data. When another SEO plugin is active, Pugmill suppresses any outputs that plugin already handles — no duplicate meta tags or JSON-LD nodes.

**AEO Health Score**
A sidebar score (0–100) on every post showing which AEO fields are complete and what is missing.

**Markdown endpoints**
Each post is available at `/?aeopugmill_llm=1` as a clean Markdown rendering — metadata, summary, entities, Q&A pairs, and full content — structured for AI parsing without HTML markup or theme chrome.

**REST API integration**
All AEO metadata is included in WordPress REST API responses, making content AI-ready for headless and decoupled architectures.

= Modes =

**Free** — Full manual AEO toolkit. The metadata editor, llms.txt, JSON-LD schema, health score, bot analytics, and REST API integration. No account or API key required.

**AI Connector** — Activate with a license key from aeopugmill.com and connect your own Claude, GPT-4o, or Gemini API key. Generates all AEO metadata for any post in one click, and writes rough drafts into full Answer Unit structure.

= External Services =

This plugin connects to the following external services:

**Pugmill AEO Intelligence Network** (anonymous bot traffic benchmarking)
When you opt in to Bot Analytics (Settings → AEO Pugmill → Bot Analytics), the plugin:

- Registers your site with a one-way hashed site ID (SHA-256 of your home URL + a randomly generated instance ID). Your URL is never transmitted directly.
- Submits anonymized daily bot traffic counts (bot name, resource type, visit count) to aeopugmill.com once per day via a scheduled background task.
- Fetches aggregated network averages from aeopugmill.com to show how your bot traffic compares to other sites on the network.

No visitor data, IP addresses, post content, or personally identifiable information is ever transmitted. You can opt out at any time from the Bot Analytics tab; opting out stops all submissions and removes your site from the network.

- Service: [https://aeopugmill.com](https://aeopugmill.com)
- Privacy Policy: [https://aeopugmill.com/privacy](https://aeopugmill.com/privacy)
- Terms of Service: [https://aeopugmill.com/terms](https://aeopugmill.com/terms)
- This connection only occurs after explicit opt-in. Free mode users who have not opted in make no connections to aeopugmill.com.

**Pugmill License Server** (license validation)
When a license key is entered in Settings → AEO Pugmill, the plugin contacts the aeopugmill.com API to validate the license. This sends your license key and your site's domain to the license server.

- Service: [https://aeopugmill.com](https://aeopugmill.com)
- Privacy Policy: [https://aeopugmill.com/privacy](https://aeopugmill.com/privacy)
- Terms of Service: [https://aeopugmill.com/terms](https://aeopugmill.com/terms)
- This connection only occurs when a license key is entered. Free mode users make no external connections.

**AI Providers** (AI Connector mode only)
When an AI API key is configured and either "Generate with AI" or "Write from Draft" is clicked by an admin, the plugin sends data to the chosen AI provider. No connection is made automatically — only on explicit admin action.

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
- Bot traffic data submitted to the network is anonymized — the site URL is never transmitted; only a one-way hash is used
- AI API keys are encrypted at rest using AES-256-CBC
- License keys are encrypted at rest using AES-256-CBC
- All external connections use HTTPS with SSL verification

== Installation ==

1. Upload the `aeo-pugmill` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Settings → AEO Pugmill** to configure
4. Edit any post or page — the AEO metadata editor and health score appear in the sidebar
5. Visit `yoursite.com/llms.txt` to confirm the endpoint is active

= AI Connector Setup =

1. Purchase a license at [aeopugmill.com/pricing](https://aeopugmill.com/pricing)
2. Enter your license key in **Settings → AEO Pugmill**
3. Select your AI provider and enter your API key
4. Open any post and click **Generate with AI**

== Frequently Asked Questions ==

= What is a pugmill? =
In a ceramics studio, a pugmill is a machine that reclaims clay. When clay gets overworked or mixed with too much water it becomes slop — potters' shorthand for waterlogged, unusable material. The pugmill takes that slop, compresses it, forces out the air bubbles, and extrudes it as clean, wedged, ready-to-use clay. This plugin does the same thing to your content: takes the good parts of your existing work, removes the filler, and structures it into something AI engines can read and cite.

= What is AEO? =
AEO (Answer Engine Optimization) is the practice of structuring content so AI answer engines — ChatGPT, Perplexity, Gemini, and others — can retrieve, cite, and surface it in response to user questions.

= What is llms.txt? =
llms.txt is an emerging open standard (similar to robots.txt) that gives AI crawlers a structured index of a site's content. AEO Pugmill generates and maintains this file automatically.

= Do I need an AI API key to use this plugin? =
No. The free version includes all manual AEO tools — the metadata editor, llms.txt, JSON-LD schema, health score, bot analytics, and REST API integration. An AI API key is only needed for one-click AI generation in AI Connector mode.

= Which AI providers are supported? =
Anthropic (Claude), OpenAI (GPT-4o), and Google (Gemini). All three work with your own API key in AI Connector mode.

= Is my API key secure? =
API keys and license keys are encrypted at rest using AES-256-CBC, keyed to the WordPress installation. They are stored server-side only and never exposed to site visitors.

= Will this slow down my site? =
AEO Pugmill adds no frontend JavaScript. JSON-LD is generated server-side. The llms.txt endpoints are cached with a one-hour TTL and are requested by bots, not visitors.

= Does this work with my existing SEO plugin? =
AEO Pugmill detects common SEO plugins and suppresses any outputs they already handle — no duplicate meta tags or JSON-LD nodes. The Compatibility tab on the settings page shows exactly which outputs are active and which are deferred.

= What does AEO Pugmill cover that a standard SEO plugin does not? =
Three outputs standard SEO plugins do not generate:

1. **FAQPage JSON-LD** — Q&A pairs structured for AI answer engines and rich results
2. **Entity graph with sameAs links** — typed entity mentions (Person, Organization, Product) linking to authoritative references for AI disambiguation
3. **Citation extraction** — external links from post content structured as schema.org citations

Plus llms.txt, per-post Markdown endpoints, and a bot analytics dashboard.

== Screenshots ==

1. AEO metadata editor on the post edit screen
2. AEO Health Score sidebar widget
3. Settings page showing license and AI provider configuration
4. Example llms.txt output

== Changelog ==

= 1.1.1 =
* **Code quality**: WordPress.org Plugin Check compliance — output escaping, input sanitization, i18n translators comments, `wp_parse_url`/`wp_strip_all_tags` substitutions, and `error_log` calls gated on `WP_DEBUG`. No functional changes.

= 1.1.0 =
* **Breaking**: Bot analytics v4 schema — `bot_daily` rekeyed from `(bot_id tinyint, resource_type, day)` to `(day, bot_name varchar(64), resource_type)`. On upgrade the plugin DROPs the legacy `bot_daily`, `bot_recent`, and `bot_visits` tables and recreates the new shape. Historical local data is lost; the network-side aggregate is the source of truth.
* **Bot identity**: Unknown bots are no longer collapsed into a single `bot_id=0` row and no longer flattened to `'Other'` in the network payload. Every distinct UA-derived name is preserved end-to-end and classified server-side.
* **Capture**: Dual-phase hook (`init:99` stash + `template_redirect:1` finalize) replaced with a single `shutdown:1` capture point. `$wp_query` is fully populated by then, so AEO post detection (resource type 7) works correctly for every frontend request path.
* **Removed**: `bot_recent` ring buffer, Recent Visits admin table, Top Posts panel, and Recent Visits CSV export — the network aggregate carries this signal now.
* **Network payload**: `schema_ver` bumped to 4. Bot keys in the `bots` map are the opaque captured names (no allowlist drops).

= 1.0.7 =
* **UI**: "Generate All" renamed to "Generate AEO" across Dashboard, Audit AEO, Bulk AEO, and sidebar.
* **UI**: Sidebar AI buttons (Tone Check, Topic Focus, Refine, Internal Links, Reading Level) converted from full-width buttons to compact AiPill components with shorter labels.
* **UI**: AI-gated features show an "AI" pill badge when locked; Pro-gated features show a "Pro" pill badge. Replaces plain text hints and faded buttons.
* **UI**: Site AEO "Draft with AI" and "Get AI Improvement Tips" now show disabled buttons with AI pill when no API key is configured.
* **UI**: "Settings" panel renamed to "Preferences" on the dashboard.
* **Fix**: Network slug mapping for aeo_post and aeo_jsonld resource types — enables network comparison arrows in Content Reach.
* **Fix**: Network API URL corrected from www.aeopugmill.com to aeopugmill.com.
* **UI**: Dashboard charts rendered as separate bordered panels. Network legend replaced with visual bar/dot swatches and "Resync now" link with last-sync timestamp.

= 1.0.1 =
* **Fix**: AI Provider form now saves API keys correctly — restored the JavaScript change-detection listener lost during dashboard consolidation.
* **Tests**: Updated PHP test suite — removed stale audit.php references, added on-page SEO store tests, bumped version constant. All 205 tests pass (65 PHP plugin + 23 PHP encryption + 117 JS).

= 1.0.0 =
* **Dashboard consolidation**: Settings page restructured from 8 tabs to 5. New Dashboard tab is the default, with three inline setup cards (AI Provider, Author Voice, Pro License) that expand on demand and collapse when configured. Bot Analytics content now lives directly in the Dashboard.
* **Data visualizations**: Content Reach diet bars, Crawl Intelligence quality bars, Top Posts visit bars, dot markers for network averages, and proportional bubble chart for Bot Activity.
* **Visual polish**: Consistent bar weights, solid track backgrounds, larger legends, title case headings throughout.
* First public release — prepared for WordPress.org submission.

== Upgrade Notice ==

= 1.1.1 =
Code quality and escaping fixes for WordPress.org submission. Safe to upgrade — no functional changes, no database changes.

= 1.1.0 =
Bot analytics v4 — schema rewrite. The legacy bot tables are DROPped on upgrade and rebuilt with opaque bot names. Local historical bot data is lost; the network-side aggregate is the source of truth. Safe to upgrade if you rely on the network dashboard rather than the per-site bot history.

= 1.0.7 =
UI refinements and network fixes. Safe to upgrade — no database changes.

= 1.0.1 =
Fixes AI Provider key save regression from 1.0.0 dashboard consolidation. Safe to upgrade — no database changes.

= 1.0.0 =
First public release.

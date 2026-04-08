=== WP Pugmill ===
Contributors: janzenworks
Tags: AEO, answer engine optimization, AI, llms.txt, schema, structured data, SEO
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.26
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transform your WordPress content from SEO slop into AI-ready clay — structured, de-aired, and built for answer engines like ChatGPT, Perplexity, and Gemini.

== Description ==

**WP Pugmill** is the first WordPress plugin built specifically for the age of answer engines. While traditional SEO optimizes for Google's blue links, AEO (Answer Engine Optimization) optimizes for AI systems that synthesize and cite content directly.

When someone asks ChatGPT, Perplexity, or Gemini a question your content answers — WP Pugmill helps make sure they find and cite you.

= The Name =

In a ceramics studio, a pugmill is a machine potters use to reclaim clay. When clay gets overworked, mixed with too much water, or simply neglected, it becomes slop — raw, waterlogged, unusable. Potters call it slop for a reason. The pugmill takes that slop, compresses it, forces out the air, and extrudes it as wedged, ready-to-use clay.

Old-school SEO has the same problem. Years of keyword stuffing, thin content, and chasing blue links has left most sites full of digital slop — technically published, but not structured for how AI systems actually read and cite content. The information is in there. It's just not usable yet.

WP Pugmill does for your content what a ceramic pugmill does for clay. It takes what you've already built — the good parts of your existing SEO — breaks it down, removes the air, and transforms it into structured, AI-ready signal that answer engines like ChatGPT, Perplexity, and Gemini can actually consume and cite.

De-aired. Wedged. Ready.

= What WP Pugmill Does =

**Pugmill Intelligence Network**
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
Automatically injects Article and FAQPage schema on every post and page based on your AEO metadata — no configuration required.

**AEO Health Score**
A sidebar score (0-100) on every post shows exactly what AEO fields are complete and what's missing, with actionable tips for each gap.

**REST API Integration**
All AEO metadata is exposed on WordPress REST API responses, making your content AI-ready for headless and decoupled architectures.

= Three Modes =

**Free** — Full manual AEO toolkit. Install, fill in your AEO fields, and your content is immediately more AI-discoverable. No account required.

**AI Connector** — Activate with a license key from wppugmill.com and connect your own Claude, GPT-4, or Gemini API key. Generate all AEO metadata for any post in one click, use **Write from Draft** to rewrite your rough draft into full Answer Unit structure, and distribute your content with AI-written social drafts, excerpt copy, and internal link suggestions.

**Pro** *(Coming Soon)* — AI generation powered by WP Pugmill infrastructure. No API key needed. Includes bulk generation, site-wide AEO dashboard, and author voice training.

= External Services =

This plugin connects to the following external services:

**Pugmill Intelligence Network** (anonymous bot traffic benchmarking)
When you opt in to Bot Analytics (Settings → WP Pugmill → Bot Analytics), the plugin:
- Registers your site with a one-way hashed site ID (SHA-256 of your home URL + a randomly generated instance ID). Your URL is never transmitted directly.
- Submits anonymized daily bot traffic counts (bot name, resource type, visit count) to pugmill.dev once per day via a scheduled background task.
- Fetches aggregated network averages from pugmill.dev to show how your bot traffic compares to other sites on the network.

No visitor data, IP addresses, post content, or personally identifiable information is ever transmitted. You can opt out at any time from the Bot Analytics tab; opting out stops all submissions and removes your site from the network.
- Service: [https://pugmill.dev](https://pugmill.dev)
- Privacy Policy: [https://pugmill.dev/privacy](https://pugmill.dev/privacy)
- Terms of Service: [https://pugmill.dev/terms](https://pugmill.dev/terms)
- This connection only occurs after explicit opt-in. Free mode users who have not opted in make no connections to pugmill.dev.

**Lemon Squeezy** (license validation)
When a license key is entered in Settings → WP Pugmill, the plugin contacts the Lemon Squeezy API to validate and activate the license. This sends your license key and a unique site instance ID to Lemon Squeezy servers.
- Service: [https://lemonsqueezy.com](https://lemonsqueezy.com)
- Privacy Policy: [https://lemonsqueezy.com/privacy](https://lemonsqueezy.com/privacy)
- Terms of Service: [https://lemonsqueezy.com/terms](https://lemonsqueezy.com/terms)
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

1. Upload the `wp-pugmill` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Settings → WP Pugmill** to configure
4. Edit any post or page — you will see the AEO metadata editor and health score
5. Visit `yoursite.com/llms.txt` to confirm the endpoint is working

= AI Connector Setup =

1. Purchase a license at [wppugmill.com/pricing](https://wppugmill.com/pricing)
2. Enter your license key in **Settings → WP Pugmill**
3. Select your AI provider and enter your API key
4. Open any post and click **Generate with AI**

== Frequently Asked Questions ==

= What is a pugmill? =
In a ceramics studio, a pugmill is a machine that reclaims clay. When clay gets overworked or mixed with too much water it becomes slop — potters' shorthand for waterlogged, unusable material. The pugmill takes that slop, compresses it, forces out the air bubbles, and extrudes it as clean, wedged, ready-to-use clay. This plugin does the same thing to your content: takes the good parts of your existing SEO, removes the filler, and structures it into something AI engines can actually use.

= What is AEO? =
AEO (Answer Engine Optimization) is the practice of structuring your content so AI answer engines like ChatGPT, Perplexity, and Gemini can easily understand, cite, and surface it in response to user questions.

= What is llms.txt? =
llms.txt is an emerging open standard (similar to robots.txt) that helps AI systems discover and understand your site's content. WP Pugmill automatically generates and maintains this file.

= Do I need an AI API key to use this plugin? =
No. The free version includes all manual AEO tools — the metadata editor, llms.txt, JSON-LD schema, health score, and REST API integration. An AI API key is only needed for the AI Connector (one-click generation) feature.

= Which AI providers are supported? =
Anthropic (Claude), OpenAI (GPT-4o), and Google (Gemini). You can use any of these with your own API key in AI Connector mode.

= Is my API key secure? =
Yes. API keys and license keys are encrypted at rest using AES-256-CBC encryption, keyed to your WordPress installation. They are stored server-side only and never exposed to site visitors.

= Will this slow down my site? =
No. WP Pugmill adds zero frontend JavaScript. JSON-LD is generated server-side. The llms.txt endpoints are cached (1 hour TTL) and only hit by AI crawlers, not human visitors.

= Does this work with my existing SEO plugin? =
Yes. WP Pugmill is focused on AEO (AI discoverability) and does not conflict with SEO plugins like Yoast or RankMath. They complement each other.

== Screenshots ==

1. AEO metadata editor on the post edit screen
2. AEO Health Score sidebar widget
3. Settings page showing license and AI provider configuration
4. Example llms.txt output

== Changelog ==

= 1.0.26 =
* **Internal**: Bot signal capture upgraded to store signals per-bot (schema v2). Enables the upcoming Crawl Intelligence UI table.

= 1.0.25 =
* **Feature**: GitHub-based auto-update re-enabled (Plugin Update Checker). Sites running WP Pugmill will now receive update notifications in WP Admin → Plugins automatically.
* **Feature**: Bot signal capture — anonymised content signals (word count, content freshness, fact density, etc.) are now captured server-side and submitted to the Pugmill Intelligence Network daily.

= 1.0.24 =
* **Feature**: AI-powered "Get steps" button on each plugin conflict in the Compatibility tab — gives step-by-step instructions for resolving the conflict using the configured AI provider.
* **Fix**: Removed false-positive WordPress core sitemap conflict (core serves /wp-sitemap.xml, not /sitemap.xml — no real conflict).

= 1.0.23 =
* **Fix**: Reorder Bot Analytics quadrant grid — AI Crawlers (top-left), Search Engines (top-right), SEO Bots (bottom-left), Training Crawlers (bottom-right). Mobile view stacks in the same order.
* **Fix**: Removed Plugin Update Checker (GitHub-based auto-update) in preparation for WordPress.org directory submission.
* **Docs**: Added Pugmill Intelligence Network to External Services disclosure in readme.txt.
* **Code**: Clarified that WPPUGMILL_NETWORK_SECRET is a public protocol version identifier, not a private secret.

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
* **Feature**: Bot Analytics tab now shows a "Network Trends" strip with four category cards (AI Crawlers, Training, Search, SEO Bots) sourced from the Pugmill Intelligence Network — displays network-wide visit totals and % change vs prior 30 days for each category.
* **Enhancement**: Bot Analytics expanded from 12 to 25 known bots across four categories: AI companies (ChatGPT, Claude, Perplexity, Gemini, Amazonbot, Meta, Mistral), training crawlers (Bytespider, Cohere, DeepSeek, Grok, CCBot), traditional search engines (Googlebot, GoogleOther, Bingbot, YandexBot, BaiduBot, Applebot, DuckDuckGo), and commercial SEO bots (Semrush, Ahrefs, Dotbot, Majestic, Barkrowler, AI2Bot).
* **Feature**: Unknown bot catch-all — any unrecognized bot-like User-Agent is now detected, logged under "Other," and included in the recent activity feed with its parsed name.
* **Fix**: Bot Analytics tab no longer shows zero-visit bot cards — only bots with actual recorded visits appear.

= 1.0.14 =
* **Feature**: Plugin Compatibility tab now shows a side-by-side file comparison for sitemap.xml, llms.txt, and robots.txt — each column loads the live URL output and shows a WP Pugmill preview, with radio buttons to choose which plugin handles each file.
* **Feature**: New options to disable WP Pugmill's sitemap.xml generator and robots.txt additions independently (defers to other plugins like Jetpack, Yoast, or Rank Math).
* **Enhancement**: Compatibility checker now detects sitemap and robots.txt conflicts from Jetpack, Yoast SEO, Rank Math, AIOSEO, Google XML Sitemaps, and XML Sitemap & Google News.

= 1.0.13 =
* **Bug fix**: AEO Health fix buttons now auto-expand the corresponding lower panel (AI Summary, Q&A Pairs, Named Entities, Keywords) after generation so the user can see and save the result.
* **Bug fix**: "Keywords found in content" no longer shows a broken fix button — it now redirects to the Keyword Coverage tool in Topic Audit where the actual content rewriting happens.
* **UX**: Health fix "Done" state now reads "Applied — save post to keep" to prompt the user to save.

= 1.0.12 =
* **Bug fix**: "Keywords found in content" fix button now correctly shows as inactive for non-Pro users instead of showing as clickable and then throwing a license error.
* **UI**: Generate All button now shows a "Available with WP Pugmill Pro" note for non-Pro users.
* **Fix**: Pro license error message no longer duplicates "WP Pugmill" in the text.

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
* **Feature**: Pugmill Intelligence Network authentication — daily submissions are now signed with a per-site HMAC token obtained at opt-in registration. Prevents spoofed data from reaching the network.
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
* **AI Insights**: Five enhancements — AEO conversion rate (AEO endpoint hits as % of total crawl), 15-day traffic trend with per-bot direction and change %, Pugmill Intelligence Network benchmark with above/below-average signals, zero-visit gap callout for bots crawling other sites but not yours, and richer system prompt context for more actionable recommendations. Max tokens increased to 750.
* **Bot Analytics dashboard**: Replaced "All-time visits" card with per-category 30-day summary cards (AI Crawlers and Search Spiders) showing visit totals and percentage split. New symmetric 7-box layout: one summary card plus six per-bot cards per row.

= 0.9.0 =
* **Social Media Draft**: New AI Connector feature generates platform-optimised social copy for LinkedIn (700 chars), X/Twitter (280), Facebook (500), and Substack Notes (300). Uses AEO metadata as the primary signal. Hard-limit backstop trims to word boundary and appends ellipsis if the AI still exceeds the limit.
* **Excerpt Generator**: New AI Connector feature generates a compelling 1–2 sentence excerpt (max 160 chars) from post title and content.
* **Distribution tab**: Social Draft and Excerpt Generator are grouped in a new Distribution section of the sidebar panel.
* **Internal Links**: Suggests 3–5 internal linking opportunities with verbatim anchor text, target URL, and surrounding context. "Insert" wraps the anchor directly in the Gutenberg block. Server-side paragraph validation ensures anchors are placed only where the exact text exists.

= 0.8.0 =
* **Pugmill Intelligence Network**: Bot Analytics now fetches anonymised per-bot, per-resource-type averages from the Pugmill network and compares your site's ratios. Network averages power the Content Reach comparison table and the AI Insights benchmark section.
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
* **AEO endpoint discovery in llms.txt**: Every post entry in `/llms.txt` and `/llms-full.txt` now includes a `Markdown:` line pointing directly to the post's `?wppugmill_llm=1` optimised endpoint. AI crawlers that read llms.txt (ChatGPT confirmed, others emerging) now have an explicit map to every structured content endpoint — no inference from HTML headers required.
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
* **User-selectable AI rate limit**: hourly call cap is now configurable in Settings → WP Pugmill → AI Provider (50 / 100 / 200 calls per hour); default remains 50
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
* Per-post `?wppugmill_llm=1` markdown endpoint for AI crawlers
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

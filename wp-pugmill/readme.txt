=== WP Pugmill ===
Contributors: janzenworks
Tags: AEO, answer engine optimization, AI, llms.txt, schema, structured data, SEO
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your WordPress content natively discoverable by AI answer engines like ChatGPT, Perplexity, and Gemini.

== Description ==

**WP Pugmill** is the first WordPress plugin built specifically for the age of answer engines. While traditional SEO optimizes for Google's blue links, AEO (Answer Engine Optimization) optimizes for AI systems that synthesize and cite content directly.

When someone asks ChatGPT, Perplexity, or Gemini a question your content answers — WP Pugmill helps make sure they find and cite you.

= What WP Pugmill Does =

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

**AI Connector** — Activate with a license key from wppugmill.com and connect your own Claude, GPT-4, or Gemini API key. Generate all AEO metadata for any post in one click, or use **Write from Draft** to rewrite your rough draft into full Answer Unit structure.

**Pro** *(Coming Soon)* — AI generation powered by WP Pugmill infrastructure. No API key needed. Includes bulk generation, site-wide AEO dashboard, and author voice training.

= External Services =

This plugin connects to the following external services:

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

- No visitor data is collected or transmitted by this plugin
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

= 0.2.0 =
Adds global AEO coverage for home/front page, Author Voice guide, Write from Draft AI feature, and inline script refactor. Safe to upgrade — no database changes.

= 0.1.0 =
Initial release.

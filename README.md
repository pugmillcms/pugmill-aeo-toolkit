# AEO Pugmill

Answer Engine Optimization for WordPress. Structures content for AI answer engines — FAQPage schema, entity graph, citations, bot analytics, and llms.txt. Works alongside Yoast, RankMath, and AIOSEO.

**Plugin:** [wordpress.org/plugins/aeo-pugmill](https://wordpress.org/plugins/aeo-pugmill/)
**Network:** [aeopugmill.com](https://aeopugmill.com)
**Pricing:** [aeopugmill.com/pricing](https://aeopugmill.com/pricing)

---

## What it does

AEO Pugmill handles the layer that traditional SEO plugins do not cover — structuring content for AI crawlers (ChatGPT, Claude, Perplexity, Gemini) rather than search engine ranking algorithms.

### Outputs injected into HTML

- **FAQPage JSON-LD** — Q&A pairs from AEO metadata rendered as `schema.org/FAQPage` in a `<script type="application/ld+json">` block
- **Entity mentions with `sameAs`** — typed entities (Person, Organization, Product, etc.) added as `mentions` in the Article JSON-LD node, linked to Wikipedia or official URLs for disambiguation
- **Citation JSON-LD** — external links extracted from post content and structured as `schema.org/citation` entries
- **Meta tags** — `description`, Open Graph, and Twitter Card tags derived from the AEO summary (suppressed when an SEO plugin conflict toggle is enabled)

### Outputs served at separate URLs

- `/llms.txt` — plain-text site index following the [llms.txt specification](https://llmstxt.org)
- `/llms-full.txt` — paginated full-content export
- `/your-post/?aeopugmill_llm=1` — single post rendered as structured Markdown with metadata, summary, entities, Q&A, and full content
- `/?aeopugmill_llm=1` — site summary with recent posts and links to the full index
- `/aeo/your-post.jsonld` — standalone JSON-LD file with FAQPage, entities, citations, and a link to the Markdown endpoint
- `/sitemap.xml` — standard XML sitemap with `xhtml:link` alternates pointing to Markdown endpoints
- `robots.txt` additions — `Sitemap` and `LLMs-Txt` directives appended to the WordPress-generated file

### Bot analytics

Identifies 25 bot signatures via user-agent substring matching — AI answer engines, training crawlers, search engines, and SEO tools. Logs bot name, resource type, and date in a daily summary table. Content signals (word count, freshness, fact density, URL depth) are recorded for HTML page requests.

### Network intelligence

Opt-in anonymous contribution to the [AEO Pugmill Intelligence Network](https://aeopugmill.com). Daily count summaries (bot name, resource type, visit total) are sent to the network API. No URLs, content, or user data is transmitted. The site identifier is a one-way SHA-256 hash that cannot be reversed to recover the domain.

---

## Requirements

- WordPress 6.3+
- PHP 8.1+

## Installation

1. Upload the `aeo-pugmill` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu
3. Go to **Settings → AEO Pugmill** to configure
4. Edit any post — the AEO metadata editor and health score appear in the sidebar

## Pro activation

1. Purchase a license at [aeopugmill.com/pricing](https://aeopugmill.com/pricing)
2. Enter the key in **Settings → AEO Pugmill → Preferences → Manage License**
3. Select an AI provider (Anthropic, OpenAI, or Google) and enter your API key
4. One-click generation, bulk processing, and content analysis features are enabled

One license covers up to 3 WordPress installs.

## SEO plugin compatibility

AEO Pugmill detects Yoast SEO, RankMath, AIOSEO, SEOPress, and The SEO Framework. When one is active, toggles are provided to suppress overlapping outputs (meta tags, sitemaps, breadcrumbs). AEO-specific outputs — FAQPage schema, entity mentions, citations, llms.txt, and bot analytics — do not overlap with standard SEO plugin functionality.

## Privacy

- No visitor data, IP addresses, or personally identifiable information is collected
- Network participation requires explicit opt-in
- Bot traffic data is anonymized — the site URL is never transmitted
- API keys and license keys are encrypted at rest using AES-256-CBC
- AI provider connections occur only on explicit admin action

## External services

| Service | When | What is sent |
|---------|------|-------------|
| [aeopugmill.com](https://aeopugmill.com) (network) | Daily, after opt-in | Hashed site ID, bot visit counts, plugin version |
| [aeopugmill.com](https://aeopugmill.com) (license) | On key entry | License key, domain |
| AI provider (Anthropic/OpenAI/Google) | On admin click | Post title + body text (truncated to 8,000 chars) |

## License

GPL-2.0-or-later — [gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

Built by [Janzen Works](https://janzenworks.com).

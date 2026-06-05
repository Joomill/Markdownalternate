# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Joomla 5/6 **system plugin** (`plg_system_markdownalternate`) that serves Markdown renditions of `com_content` articles and categories, intended for LLMs and AI agents. It exposes the same content three ways:

- `.md` URL suffix (e.g. `/blog/my-article.md`)
- `Accept: text/markdown` request header
- `?output=markdown` query parameter

It also injects a `<link rel="alternate" type="text/markdown">` tag into the HTML `<head>` of article/category pages for discoverability.

There is **no build, test, or lint tooling**. The repo is the deployable plugin: edit PHP/XML in place. Deployment is done from PhpStorm to the deploy server (not via this repo). Releases are packaged as a zip (see `.gitignore`).

## Architecture

Two files do everything:

- `services/provider.php` — DI container service provider. Wires the `DispatcherInterface` + `PluginHelper::getPlugin(...)` params, the application, and the `DatabaseInterface`. Uses `$container->lazy()` on Joomla 6.1+ with a `method_exists()` guard that falls back to a plain `set()` on Joomla 5 / 6.0.
- `src/Extension/Markdownalternate.php` — the entire plugin. A `final` class (`declare(strict_types=1)`) extending `CMSPlugin`, implementing `SubscriberInterface`, and using `DatabaseAwareTrait`.

Namespace: `Joomill\Plugin\System\Markdownalternate`. Manifest: `markdownalternate.xml`.

### Request lifecycle (the three event handlers)

1. **`onAfterInitialise`** — site only. Reads request data via the `Input` object (not raw superglobals) to detect whether Markdown was requested (query param / Accept header / `.md` suffix). For `.md` URLs it strips the suffix from `Uri::getInstance()` and mirrors the cleaned value onto `REQUEST_URI` (which the SEF router consumes), and remembers the original path in `$originalPath`.
2. **`onAfterRoute`** — if Markdown was requested and the route resolved to a `com_content` `article` or `category` view with `id >= 1`, it loads data, builds the Markdown, sets `Content-Type: text/markdown`, an `X-Markdown-Tokens` estimate (`strlen/4`) and a `Link: …; rel="canonical"` header through `$app->setHeader()` + `sendHeaders()`, echoes, and calls `$app->close()`. `?output=markdown&debug=1` on an article instead dumps a plaintext debug report (custom-field diagnostics).
3. **`onBeforeRender`** — when Markdown was *not* requested, adds the `<link rel="alternate">` head tag (guarded by the `show_link` param and `alternate_url_format`: `.md` suffix vs `?output=markdown`). The URL is HTML-attribute-escaped before `addHeadLink()` because the Joomla head layout does not escape it. Only acts on `HtmlDocument`.

### Critical constraint: data is loaded via raw DB, not models

`loadArticle()` / `loadCategory()` / `loadCustomFieldsFromDb()` query `#__content`, `#__categories`, `#__users`, `#__tags`, `#__fields`, `#__fields_values` directly via `$this->getDatabase()` (injected `DatabaseInterface`).

**Do not replace these with the com_content `ArticleModel` or `FieldsHelper`.** During `onAfterRoute` the Joomla document object is not yet fully initialised; those APIs call `setMetaData()` on a null document and fatal. This is intentional and documented in the code comments — preserve it. Article body is reconstructed as `introtext` + `fulltext` to mirror what the model produces.

Because the model is bypassed, access control must be applied manually: every content query filters `access IN getViewLevels()` (the current visitor's authorised view levels) so the `.md` rendition mirrors the HTML view and never leaks registered-only content. Keep this filter on any new content query.

### Markdown generation pipeline

- `buildMarkdownResponse()` / `buildCategoryMarkdownResponse()` — assemble YAML frontmatter (title, date, description, author, images, categories, tags) + body. Each section is gated by a `show_*` plugin param. Frontmatter scalars go through `yamlQuote()` (always double-quoted, escapes newlines/quotes); don't reintroduce `addslashes()` here.
- `htmlToMarkdown()` — a self-contained `DOMDocument`-based HTML→Markdown converter (`convertNode()` switch over tags). No external library. Handles headings, lists, tables, code blocks, blockquotes, links/images (relative→absolute), and strips `script`/`style`/`iframe`/form elements.
- `stripShortcodes()` — removes Joomla `{plugin ...}` shortcodes via regex before conversion.
- Custom fields render at the end under `## Custom Fields`. `renderFieldAsMarkdown()` / `renderSubformValue()` resolve option keys to labels, handle `media`/`image`, and recursively render `subform` rows (child labels looked up from `#__fields`).
- `cleanImageUrl()` strips `#joomlaImage://…` fragments and absolutises paths; `getAbsoluteBaseUrl()` builds the site root with several fallbacks.

## Conventions

- Every PHP file starts with the Joomill header comment block, `declare(strict_types=1)`, and `defined('_JEXEC') or die;`.
- Plugin params are read with `$this->params->get('show_x', 1)` — default-on. New output sections should follow the same `show_*` radio-switcher pattern in both the code and `markdownalternate.xml` `<config>`.
- User-facing strings live in `language/en-GB` + `language/nl-NL` (`.ini` + `.sys.ini`) as `PLG_SYSTEM_MARKDOWNALTERNATE_*` constants; the manifest references the constants, not literal text. New fields need a `_FIELD_<NAME>_LABEL` key in all four files.
- When touching `markdownalternate.xml`, apply the house manifest standard (element order, section comments) from the user's Obsidian snippet `30-snippets/joomla-extension-manifest.md`. The extension name string (`PLG_SYSTEM_MARKDOWNALTERNATE`) is identical in every language, never translated.
- Bump `<version>` in `markdownalternate.xml` for any release-worthy change.

## Project knowledge

Durable decisions, status, and reusable patterns for this extension live in the user's Obsidian vault under `15-extensions/`, not in this repo. Record outcomes there at the end of substantive work.

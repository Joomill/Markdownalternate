# Render content-prepare shortcodes in Markdown output

Date: 2026-06-06
Status: approved (pending spec review)
Component: `plg_system_markdownalternate`
Target version: 1.2.1

## Problem

Article and category content can contain Joomla content-plugin shortcodes:
`{loadmodule}`, `{loadposition}`, custom field shortcodes (`{field N}`),
and third-party shortcodes (sliders, tabs, Sourcerer / RegularLabs, etc.).
These are normally expanded by the `onContentPrepare` event chain that
com_content runs through its model.

The plugin bypasses the com_content model on purpose (the Joomla document
is not fully initialised during `onAfterRoute`, so `ArticleModel` /
`FieldsHelper` fatal with `setMetaData() on null`). As a result it never
runs content preparation. Today `stripShortcodes()` simply deletes every
`{plugin ...}` pattern before the HTML→Markdown conversion, and category
introtexts are not even stripped — raw shortcodes pass through as literal
text.

We want these shortcodes rendered the proper way instead of dropped.

## Goal

Run Joomla's real `onContentPrepare` chain on the content before
converting to Markdown, so shortcodes expand exactly as they would on the
HTML page, while:

- keeping the existing dedicated `## Custom Fields` section (no duplicate
  inline field output),
- never letting a third-party content plugin break the `.md` response,
- applying the same treatment to single articles and to category
  introtexts.

## Non-goals

- No change to access-level filtering, the raw-DB data loaders, or the
  dedicated custom-fields rendering.
- No caching layer (acceptable to prepare on each request; see Risks).
- No attempt to sandbox asset injection — assets added to the document by
  content plugins are harmless because the response calls `$app->close()`
  before the render stage.

## Chosen approach

Manually dispatch `onContentPrepare` from the plugin. Rejected
alternatives:

- **Via the com_content model / `ContentHelper`** — reintroduces the exact
  document-not-ready fatal the plugin was built to avoid.
- **Hand-rolling per shortcode** (`ModuleHelper`, `FieldsHelper` directly)
  — reimplements Joomla, misses all third-party shortcodes, brittle.

## Design

### New helper: `prepareContent()`

```php
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;

/** @var bool  Import content plugins (minus fields) only once per request. */
private bool $contentPluginsImported = false;

/**
 * Run Joomla's onContentPrepare chain on $item->text and return the
 * prepared text. The fields content plugin is deliberately excluded so it
 * cannot duplicate the dedicated Custom Fields section.
 *
 * Defensive: any failure falls back to the unprepared text, so a broken
 * third-party plugin can never break the Markdown response.
 */
private function prepareContent(object $item): string
{
    $text = (string) ($item->text ?? '');

    if ($text === '') {
        return '';
    }

    try {
        $this->importContentPlugins();

        $params = ComponentHelper::getParams('com_content');

        $event = new ContentPrepareEvent('onContentPrepare', [
            'context' => 'com_content.article',
            'subject' => $item,
            'params'  => $params,
            'page'    => 0,
        ]);

        $this->getApplication()->getDispatcher()->dispatch('onContentPrepare', $event);

        return (string) ($item->text ?? $text);
    } catch (\Throwable $e) {
        // Never let a content plugin break the .md response.
        return $text;
    }
}

/**
 * Import every content plugin except `fields`, once per request.
 */
private function importContentPlugins(): void
{
    if ($this->contentPluginsImported) {
        return;
    }

    foreach (PluginHelper::getPlugin('content') as $plugin) {
        if ($plugin->name === 'fields') {
            continue;
        }

        PluginHelper::importPlugin('content', $plugin->name);
    }

    $this->contentPluginsImported = true;
}
```

Notes:

- The dispatch target is `$this->getApplication()->getDispatcher()` — the
  same dispatcher `importPlugin()` registers listeners on, and the one the
  plugin itself was constructed with.
- `$item` is passed as the event subject by reference; content plugins
  modify `$item->text` in place, which we read back after dispatch.
- `params` are the real com_content component params, so plugins that read
  them behave as they do on the rendered page.

### Pipeline integration

Order matters: **prepare → HTML→Markdown → strip leftovers.**

Article body (`buildMarkdownResponse`):

```php
$body .= $this->htmlToMarkdown(
    $this->stripShortcodes($this->prepareContent($article))
);
```

`$article` already carries `id`, `catid`, `created` and `text`
(introtext + fulltext), which satisfies plugins that read those fields.

Category introtexts (`buildCategoryMarkdownResponse`), per article in the
loop:

```php
$article->text = $article->introtext;          // give the plugin a ->text
$intro = $this->stripShortcodes($this->prepareContent($article));

if (trim($intro) !== '') {
    $body .= $this->htmlToMarkdown($intro) . "\n\n";
}
```

`stripShortcodes()` is kept as a final pass: it removes any shortcode that
was NOT expanded (plugin disabled, uninstalled, or unknown), so no raw
`{...}` leaks into the Markdown.

### Fields suppression

`importContentPlugins()` imports each content plugin by name except
`fields`. Because content plugins are not yet imported at `onAfterRoute`,
skipping `fields` means it never registers a listener and therefore never
renders fields inline. The dedicated `## Custom Fields` section (built from
`loadCustomFieldsFromDb()`) stays the single source of field output and
keeps covering fields whose Automatic Display is set to "no".

Edge case: if another system plugin earlier in the request already did a
full `PluginHelper::importPlugin('content')`, `fields` would already be
registered and could render inline, causing duplication. This is rare;
documented as a known limitation rather than worked around (working around
it would mean deregistering a listener, which is fragile).

### `stripShortcodes()`

Unchanged. Still `preg_replace('/\{[a-zA-Z][^}]*\}/', '', $text)`. Known
pre-existing caveat: it can eat legitimate `{...}` in body text (e.g. code
samples). Out of scope for this change.

## Error handling

- All preparation is wrapped in `try/catch (\Throwable)`. On any error the
  helper returns the original (unprepared) text, which then still goes
  through `stripShortcodes()` + `htmlToMarkdown()`. The response degrades to
  today's behaviour rather than failing.
- This also covers any environment where `ContentPrepareEvent` is missing
  or behaves differently (older Joomla 5 point releases).

## Risks and trade-offs

- **Performance:** `onContentPrepare` now fires once per article body and
  once per category introtext. Category pages with many articles each using
  `{loadmodule}` will do more work. Acceptable; import is done once per
  request via the guard, and the `.md` endpoint is not a hot path.
- **Module noise:** `{loadmodule}`/`{loadposition}` pull full module HTML
  into the Markdown. `htmlToMarkdown()` already strips `script`/`style`/
  `iframe`/form elements, but menus/banners may still appear as content.
  This was accepted when choosing the full chain.
- **Asset injection:** harmless — `$app->close()` runs before render.

## Testing

No automated harness exists (documented in CLAUDE.md). Manual verification
on a Joomla 6 site:

1. Article with `{loadmodule mod_menu,...}` → module renders as Markdown,
   no literal `{loadmodule}` remains.
2. Article with a third-party shortcode (e.g. a tabs plugin) → expanded.
3. Article with custom fields set to Automatic Display = above/below →
   fields appear **once**, only in the `## Custom Fields` section.
4. Article with a field set to Automatic Display = no → still present in
   the `## Custom Fields` section.
5. Article whose shortcode plugin is disabled → shortcode stripped, no raw
   `{...}`, response still 200.
6. Category page whose introtexts contain shortcodes → expanded per
   article.
7. `php -l` clean on the changed file.

## Versioning

Bump `<version>` to 1.2.1.

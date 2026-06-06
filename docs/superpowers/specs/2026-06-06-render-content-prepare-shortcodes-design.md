# Render content-prepare shortcodes in Markdown output (v2)

Date: 2026-06-06 (v2 revision after the v1 production incident)
Status: draft (pending spec review)
Component: `plg_system_markdownalternate`
Target version: 1.3.0

## Revision history

- **v1 (shipped as 1.2.1, reverted in 1.2.2):** dispatched `onContentPrepare`
  for **all** content plugins (except `fields`) on the **application
  dispatcher**, from `onAfterRoute`. This broke production: on a site with a
  gating content plugin (com_ochsubscriptions), the plugin's
  `onContentPrepare` handler called `$app->redirect('login')` for anonymous
  visitors, so every `.md` and `?output=markdown` request 302-redirected to
  `/login`. A `$app->redirect()` is an `exit`, so the `try/catch` did not
  catch it. Proven by bisection: the debug path (`loadArticle` only) returned
  200; the path that ran the dispatch returned 302.
- **v2 (this document):** make the feature opt-in, run only an explicit
  allow-list of content plugins, and dispatch on an **isolated dispatcher** so
  no other registered listener (e.g. a gating plugin) can fire.

## Problem

Article and category content can contain Joomla content-plugin shortcodes:
`{loadmodule}`, `{loadposition}`, and third-party shortcodes (sliders, tabs,
etc.). The plugin bypasses the com_content model on purpose (the document is
not ready during `onAfterRoute`), so it never runs content preparation; today
`stripShortcodes()` deletes `{plugin ...}` patterns before conversion.

We want a **safe, optional** way to expand selected shortcodes.

## Root cause lesson (drives the v2 design)

`Dispatcher::dispatch('onContentPrepare', ...)` fires **every listener
registered on that dispatcher**, not just the plugins we imported. Two
consequences:

1. Running the full content-plugin set lets plugins that redirect, output, or
   make access decisions hijack the request.
2. Even importing a curated subset is not enough if we dispatch on the shared
   application dispatcher, because another extension may have already
   registered the dangerous plugin there.

Therefore v2 must both (a) limit which plugins we run and (b) dispatch on a
dispatcher that contains only those plugins.

## Goal

Optionally expand an admin-controlled allow-list of content-plugin shortcodes
in the Markdown output, with zero risk of an unrelated content plugin firing,
while keeping the dedicated `## Custom Fields` section and never breaking the
`.md` response.

## Non-goals

- No change to access filtering, the raw-DB loaders, or the dedicated
  custom-fields rendering.
- No automatic running of arbitrary/all content plugins.
- No caching layer.

## Design

### New plugin parameters

In `markdownalternate.xml` `<config>` and the language files:

- `render_shortcodes` (radio switcher, default `0` / off). Master opt-in. When
  off, behaviour is exactly today's `stripShortcodes`-only output.
- `shortcode_plugins` (text, default `loadmodule,loadposition`, shown only when
  `render_shortcodes:1`). Comma-separated list of content-plugin **element
  names** that are allowed to run during preparation.

Defaults mean a fresh install does nothing new until the admin opts in, and
even then only runs `loadmodule` / `loadposition` (neither of which redirects
or denies access).

### `prepareContent()` on an isolated dispatcher

```php
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Dispatcher;

/**
 * Expand the allow-listed content-plugin shortcodes in $item->text and return
 * the result. The input object is not modified.
 *
 * Only the plugins named in the `shortcode_plugins` param run, and they run on
 * a private dispatcher, so no other content plugin registered on the
 * application (e.g. an access/redirect plugin) can fire. Returns the original
 * text unchanged when the feature is off, the allow-list is empty, or anything
 * throws.
 */
private function prepareContent(object $item): string
{
    $text = (string) ($item->text ?? '');

    if ($text === '' || !$this->params->get('render_shortcodes', 0)) {
        return $text;
    }

    $allowed = array_filter(array_map(
        'trim',
        explode(',', (string) $this->params->get('shortcode_plugins', 'loadmodule,loadposition'))
    ));

    if (empty($allowed)) {
        return $text;
    }

    try {
        // Private dispatcher: only the allow-listed plugins are registered on
        // it, so dispatch() cannot reach any other content plugin.
        $dispatcher = new Dispatcher();

        foreach ($allowed as $name) {
            PluginHelper::importPlugin('content', $name, true, $dispatcher);
        }

        $subject = clone $item;

        $event = new ContentPrepareEvent('onContentPrepare', [
            'context' => 'com_content.article',
            'subject' => $subject,
            'params'  => ComponentHelper::getParams('com_content'),
            'page'    => 0,
        ]);

        $dispatcher->dispatch('onContentPrepare', $event);

        return (string) ($subject->text ?? $text);
    } catch (\Throwable $e) {
        return $text;
    }
}
```

Notes:

- `PluginHelper::importPlugin('content', $name, true, $dispatcher)` registers
  each allow-listed plugin's listeners on **our** dispatcher (4th argument),
  not the application's. `$checkEnabled = true` still skips disabled plugins.
- Dispatching on `$dispatcher` fires only those plugins. A gating plugin that
  is not in the allow-list never runs, even if it is registered on the
  application dispatcher — this is the concrete fix for the v1 incident.
- The `fields` plugin is simply absent from the default allow-list, so the
  dedicated Custom Fields section is never duplicated. No special-casing
  needed. (If an admin adds `fields`, inline duplication is their choice.)
- `clone $item` keeps the helper pure (no caller mutation, no half-prepared
  state on throw), as established in the v1 review.

### Pipeline integration

Unchanged from v1, order **prepare → stripShortcodes → htmlToMarkdown**:

- Article body (`buildMarkdownResponse`):
  `htmlToMarkdown(stripShortcodes(prepareContent($article)))`.
- Category introtexts (`buildCategoryMarkdownResponse`): set
  `$article->text = $article->introtext`, then the same wrapping with an
  empty-string guard.

With the feature off (default), `prepareContent()` returns the text untouched,
so the only effect is the existing `stripShortcodes()` pass — i.e. no
behaviour change for current installs.

`stripShortcodes()` stays as the final pass to remove any shortcode that no
allow-listed plugin expanded.

### Category article query

`loadCategory()` selects `catid`, `created`, `language` in addition to the
existing columns, so allow-listed plugins on the introtext path see the same
fields as the single-article path. (These were added in v1; re-add them.)

## Error handling

- Feature off, empty allow-list, or empty text → return the original text.
- `try/catch (\Throwable)` → return the original text on any exception.
- A redirect cannot occur with the default allow-list (`loadmodule` /
  `loadposition` do not redirect). If an admin allow-lists a plugin that does
  redirect, that is an explicit, documented choice.

## Risks and trade-offs

- **Admin can still allow-list a bad plugin.** Mitigated by: opt-in default
  off, a safe default list, and documentation. We do not try to detect
  redirecting plugins.
- **Performance:** preparation runs only when opted in, and only the
  allow-listed plugins run, on a throwaway dispatcher per call. Acceptable;
  building the dispatcher is cheap.
- **Module noise:** `{loadmodule}` / `{loadposition}` pull module HTML into the
  Markdown; `htmlToMarkdown()` strips script/style/iframe/form. Accepted.

## Testing

No automated harness (see `CLAUDE.md`). `php -l` per change. Manual checklist on
a Joomla 6 site, including the exact regression that caught v1:

1. Feature **off** (default): `.md` and `?output=markdown` return **200**
   Markdown; behaviour identical to 1.2.2. **This is the v1 regression guard.**
2. Feature **on**, default allow-list, article with `{loadmodule ...}` /
   `{loadposition ...}` → module renders as Markdown; no literal shortcode
   left; response still **200** (no `/login` redirect).
3. Feature **on**, on a site with a gating content plugin NOT in the allow-list
   → `.md` still returns **200** (the gating plugin must not fire).
4. Custom fields appear **once**, only in the `## Custom Fields` section.
5. Feature **on**, allow-list emptied → returns un-prepared text, **200**.
6. Category introtexts with allow-listed shortcodes → expanded per article.
7. `php -l` clean; manifest `XML OK`.

Check #1 and #3 are the regression guards for the production incident.

## Versioning

Bump `<version>` to 1.3.0 (new, opt-in feature).

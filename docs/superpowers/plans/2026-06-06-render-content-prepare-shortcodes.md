# Render content-prepare shortcodes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run Joomla's `onContentPrepare` chain on article and category content so shortcodes (`{loadmodule}`, `{loadposition}`, third-party) render properly in the Markdown output, while keeping the dedicated Custom Fields section.

**Architecture:** Add a defensive `prepareContent()` helper to the plugin that imports every content plugin except `fields` (once per request) and dispatches a typed `ContentPrepareEvent` on the application dispatcher. Wire it into the article body and category introtext rendering, before the existing `stripShortcodes()` + `htmlToMarkdown()` passes.

**Tech Stack:** PHP 8.1+, Joomla 5/6 plugin APIs (`PluginHelper`, `ContentPrepareEvent`, `ComponentHelper`, application dispatcher).

**Testing note:** This repo has no automated test harness (see `CLAUDE.md`). Per-task verification is `php -l`; behavioural verification is the manual checklist in Task 6, run on a live Joomla 6 site (not possible from the dev session).

**Lint command (reused in every task):**
```
php -l src/Extension/Markdownalternate.php
```
Ignore the `Failed loading Zend extension 'xdebug'` warning; the line that matters is `No syntax errors detected in ...`.

---

### Task 1: Add imports and the import-guard property

**Files:**
- Modify: `src/Extension/Markdownalternate.php`

- [ ] **Step 1: Add the three `use` imports**

Find this import block:

```php
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
```

Replace it with:

```php
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
```

- [ ] **Step 2: Add the guard property**

Find:

```php
    use DatabaseAwareTrait;

    private bool $markdownRequested = false;

    private string $originalPath = '';
```

Replace with:

```php
    use DatabaseAwareTrait;

    private bool $markdownRequested = false;

    private string $originalPath = '';

    /** @var bool  Import content plugins (minus fields) only once per request. */
    private bool $contentPluginsImported = false;
```

- [ ] **Step 3: Lint**

Run: `php -l src/Extension/Markdownalternate.php`
Expected: `No syntax errors detected in src/Extension/Markdownalternate.php`

- [ ] **Step 4: Commit**

```bash
git add src/Extension/Markdownalternate.php
git commit -m "Add imports and guard property for content preparation"
```

---

### Task 2: Add the `importContentPlugins()` helper

**Files:**
- Modify: `src/Extension/Markdownalternate.php`

- [ ] **Step 1: Add the helper above `stripShortcodes()`**

Find this method (the first method of the HTML→Markdown section):

```php
    private function stripShortcodes(string $text): string
    {
        // Remove Joomla plugin shortcodes: {pluginname ...} and {/pluginname}
        return preg_replace('/\{[a-zA-Z][^}]*\}/', '', $text);
    }
```

Insert the following **immediately before** it:

```php
    // -----------------------------------------------------------------------
    // Content preparation (onContentPrepare)
    // -----------------------------------------------------------------------

    /**
     * Import every content plugin except `fields`, once per request.
     *
     * Excluding `fields` stops it from rendering custom fields inline, which
     * would duplicate the dedicated Custom Fields section.
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

- [ ] **Step 2: Lint**

Run: `php -l src/Extension/Markdownalternate.php`
Expected: `No syntax errors detected in src/Extension/Markdownalternate.php`

- [ ] **Step 3: Commit**

```bash
git add src/Extension/Markdownalternate.php
git commit -m "Add importContentPlugins() helper that excludes the fields plugin"
```

---

### Task 3: Add the `prepareContent()` helper

**Files:**
- Modify: `src/Extension/Markdownalternate.php`

- [ ] **Step 1: Add the helper directly below `importContentPlugins()`**

Find the closing of `importContentPlugins()` followed by `stripShortcodes()`:

```php
        $this->contentPluginsImported = true;
    }

    private function stripShortcodes(string $text): string
```

Insert the new method between them so it reads:

```php
        $this->contentPluginsImported = true;
    }

    /**
     * Run Joomla's onContentPrepare chain on $item->text and return the
     * prepared text.
     *
     * Defensive: any failure (including a missing/incompatible event class
     * on older Joomla 5 releases) falls back to the unprepared text, so a
     * broken third-party content plugin can never break the .md response.
     *
     * @param   object  $item  Article-like object carrying a `text` property.
     * @return  string         The prepared text.
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
            return $text;
        }
    }

    private function stripShortcodes(string $text): string
```

- [ ] **Step 2: Lint**

Run: `php -l src/Extension/Markdownalternate.php`
Expected: `No syntax errors detected in src/Extension/Markdownalternate.php`

- [ ] **Step 3: Commit**

```bash
git add src/Extension/Markdownalternate.php
git commit -m "Add prepareContent() helper dispatching onContentPrepare"
```

---

### Task 4: Wire preparation into the article body

**Files:**
- Modify: `src/Extension/Markdownalternate.php` (inside `buildMarkdownResponse()`)

- [ ] **Step 1: Replace the body render line**

Find:

```php
        $body .= $this->htmlToMarkdown($this->stripShortcodes($article->text ?? ''));
```

Replace with:

```php
        $body .= $this->htmlToMarkdown($this->stripShortcodes($this->prepareContent($article)));
```

`$article` already carries `id`, `catid`, `created` and `text` (introtext + fulltext), satisfying plugins that read those properties.

- [ ] **Step 2: Lint**

Run: `php -l src/Extension/Markdownalternate.php`
Expected: `No syntax errors detected in src/Extension/Markdownalternate.php`

- [ ] **Step 3: Commit**

```bash
git add src/Extension/Markdownalternate.php
git commit -m "Render content-prepare shortcodes in the article body"
```

---

### Task 5: Wire preparation into category introtexts

**Files:**
- Modify: `src/Extension/Markdownalternate.php` (inside `buildCategoryMarkdownResponse()`, the per-article loop)

- [ ] **Step 1: Replace the introtext block**

Find:

```php
                // Intro Text
                if (!empty($article->introtext)) {
                    $body .= $this->htmlToMarkdown($article->introtext) . "\n\n";
                }
```

Replace with:

```php
                // Intro Text
                if (!empty($article->introtext)) {
                    $article->text = $article->introtext;
                    $intro         = $this->stripShortcodes($this->prepareContent($article));

                    if (trim($intro) !== '') {
                        $body .= $this->htmlToMarkdown($intro) . "\n\n";
                    }
                }
```

- [ ] **Step 2: Lint**

Run: `php -l src/Extension/Markdownalternate.php`
Expected: `No syntax errors detected in src/Extension/Markdownalternate.php`

- [ ] **Step 3: Commit**

```bash
git add src/Extension/Markdownalternate.php
git commit -m "Render content-prepare shortcodes in category introtexts"
```

---

### Task 6: Bump version and verify

**Files:**
- Modify: `markdownalternate.xml`

- [ ] **Step 1: Bump the version**

Find:

```xml
    <version>1.2.0</version>
```

Replace with:

```xml
    <version>1.2.1</version>
```

- [ ] **Step 2: Validate manifest XML**

Run: `php -r '$d=new DOMDocument(); echo $d->load("markdownalternate.xml")?"XML OK\n":"XML INVALID\n";'`
Expected: `XML OK`

- [ ] **Step 3: Commit**

```bash
git add markdownalternate.xml
git commit -m "Bump to 1.2.1 for content-prepare shortcode rendering"
```

- [ ] **Step 4: Manual verification on a live Joomla 6 site**

This cannot be done from the dev session; run it after deploy. Each check must pass:

1. Article with `{loadmodule mod_menu,...}` (or `{loadposition <pos>}`) requested as `.md` → the module renders as Markdown; no literal `{loadmodule}` / `{loadposition}` remains.
2. Article using a third-party shortcode (e.g. a tabs/accordion plugin) → expanded in the Markdown, not stripped.
3. Article with a custom field set to Automatic Display = above/below content → the field appears **once**, only in the `## Custom Fields` section (no inline duplicate).
4. Article with a custom field set to Automatic Display = no → still present in the `## Custom Fields` section.
5. Article whose shortcode plugin is disabled → the shortcode is stripped, no raw `{...}` in output, response is still HTTP 200.
6. Category page whose article introtexts contain shortcodes → expanded per article in the `.md` output.

---

## Post-implementation

After the plan is complete and manually verified, update the vault note `15-extensions/joomill-extensions/markdownalternate.md` with the feature and decisions, and confirm the two open behavioural risks from the spec (module noise, the rare fields-already-imported duplication case) did not surface in testing.

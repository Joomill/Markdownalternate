# Render content-prepare shortcodes Implementation Plan (v2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an opt-in, allow-listed way to expand selected content-plugin shortcodes ({loadmodule}, {loadposition}, trusted third-party) in the Markdown output, dispatched on an isolated dispatcher so no other content plugin can fire.

**Architecture:** A `prepareContent()` helper that, only when the `render_shortcodes` param is on, imports the `shortcode_plugins` allow-list onto a private `Joomla\Event\Dispatcher` and dispatches `ContentPrepareEvent` on it. Default off; default allow-list `loadmodule,loadposition`. Wired into the article body and category introtexts before `stripShortcodes()` + `htmlToMarkdown()`.

**Tech Stack:** PHP 8.1+, Joomla 5/6 (`PluginHelper::importPlugin` with a custom dispatcher, `ContentPrepareEvent`, `ComponentHelper`).

**Background:** This replaces the reverted v1 (1.2.1) which dispatched all content plugins on the application dispatcher and let a gating plugin redirect to /login. See `docs/superpowers/specs/2026-06-06-render-content-prepare-shortcodes-design.md` (v2). The isolated dispatcher + allow-list + opt-in are the fix.

**Testing note:** No automated test harness (see `CLAUDE.md`). Per-task verification is `php -l`; behavioural verification is the manual checklist in Task 5 on a live Joomla 6 site.

**Lint command (every task):** `php -l src/Extension/Markdownalternate.php` — ignore the `xdebug` warning; success is `No syntax errors detected in ...`.

---

### Task 1: Add the two parameters (manifest + language)

**Files:**
- Modify: `markdownalternate.xml`
- Modify: `language/en-GB/plg_system_markdownalternate.ini`
- Modify: `language/nl-NL/plg_system_markdownalternate.ini`

- [ ] **Step 1: Add the fields to the manifest**

Find:

```xml
                    <option value="query">PLG_SYSTEM_MARKDOWNALTERNATE_FIELD_ALTERNATE_URL_FORMAT_OPTION_QUERY</option>
                </field>
            </fieldset>
```

Replace with:

```xml
                    <option value="query">PLG_SYSTEM_MARKDOWNALTERNATE_FIELD_ALTERNATE_URL_FORMAT_OPTION_QUERY</option>
                </field>
                <field
                    name="render_shortcodes"
                    type="radio"
                    label="PLG_SYSTEM_MARKDOWNALTERNATE_FIELD_RENDER_SHORTCODES_LABEL"
                    description="PLG_SYSTEM_MARKDOWNALTERNATE_FIELD_RENDER_SHORTCODES_DESC"
                    default="0"
                    layout="joomla.form.field.radio.switcher"
                >
                    <option value="0">JNO</option>
                    <option value="1">JYES</option>
                </field>
                <field
                    name="shortcode_plugins"
                    type="text"
                    label="PLG_SYSTEM_MARKDOWNALTERNATE_FIELD_SHORTCODE_PLUGINS_LABEL"
                    description="PLG_SYSTEM_MARKDOWNALTERNATE_FIELD_SHORTCODE_PLUGINS_DESC"
                    default="loadmodule,loadposition"
                    showon="render_shortcodes:1"
                />
            </fieldset>
```

- [ ] **Step 2: Add the en-GB strings**

Append to `language/en-GB/plg_system_markdownalternate.ini`:

```ini
PLG_SYSTEM_MARKDOWNALTERNATE_FIELD_RENDER_SHORTCODES_LABEL="Render Content Plugins"
PLG_SYSTEM_MARKDOWNALTERNATE_FIELD_RENDER_SHORTCODES_DESC="Run a small, explicit list of content plugins so their shortcodes (such as {loadmodule} and {loadposition}) are expanded in the Markdown. Off by default."
PLG_SYSTEM_MARKDOWNALTERNATE_FIELD_SHORTCODE_PLUGINS_LABEL="Allowed Content Plugins"
PLG_SYSTEM_MARKDOWNALTERNATE_FIELD_SHORTCODE_PLUGINS_DESC="Comma-separated list of content plugin element names allowed to run. Only add plugins you trust to transform text without redirecting or restricting access. Default: loadmodule,loadposition."
```

- [ ] **Step 3: Add the nl-NL strings**

Append to `language/nl-NL/plg_system_markdownalternate.ini`:

```ini
PLG_SYSTEM_MARKDOWNALTERNATE_FIELD_RENDER_SHORTCODES_LABEL="Content-plugins renderen"
PLG_SYSTEM_MARKDOWNALTERNATE_FIELD_RENDER_SHORTCODES_DESC="Draai een kleine, expliciete lijst content-plugins zodat hun shortcodes (zoals {loadmodule} en {loadposition}) in de Markdown worden uitgeklapt. Standaard uit."
PLG_SYSTEM_MARKDOWNALTERNATE_FIELD_SHORTCODE_PLUGINS_LABEL="Toegestane content-plugins"
PLG_SYSTEM_MARKDOWNALTERNATE_FIELD_SHORTCODE_PLUGINS_DESC="Kommagescheiden lijst van content-plugin-elementnamen die mogen draaien. Voeg alleen plugins toe die je vertrouwt om tekst te transformeren zonder te redirecten of toegang te beperken. Standaard: loadmodule,loadposition."
```

- [ ] **Step 4: Validate manifest XML**

Run: `php -r '$d=new DOMDocument(); echo $d->load("markdownalternate.xml")?"XML OK\n":"XML INVALID\n";'`
Expected: `XML OK`

- [ ] **Step 5: Commit**

```bash
git add markdownalternate.xml language/en-GB/plg_system_markdownalternate.ini language/nl-NL/plg_system_markdownalternate.ini
git commit -m "Add render_shortcodes and shortcode_plugins parameters"
```

---

### Task 2: Add imports and the `prepareContent()` helper

**Files:**
- Modify: `src/Extension/Markdownalternate.php`

- [ ] **Step 1: Add imports**

Find:

```php
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
```

Replace with:

```php
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Dispatcher;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
```

- [ ] **Step 2: Add the helper before `stripShortcodes()`**

Find:

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
    // Content preparation (onContentPrepare, opt-in, allow-listed)
    // -----------------------------------------------------------------------

    /**
     * Expand the allow-listed content-plugin shortcodes in $item->text and
     * return the result. The input object is not modified.
     *
     * Only the plugins named in the `shortcode_plugins` param run, and they run
     * on a private dispatcher, so no other content plugin registered on the
     * application (e.g. an access/redirect plugin) can fire. Returns the
     * original text unchanged when the feature is off, the allow-list is empty,
     * or anything throws.
     *
     * @param   object  $item  Article-like object carrying a `text` property.
     * @return  string         The prepared text.
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
            // Private dispatcher: only the allow-listed plugins are registered
            // on it, so dispatch() cannot reach any other content plugin.
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

- [ ] **Step 3: Lint**

Run: `php -l src/Extension/Markdownalternate.php`
Expected: `No syntax errors detected in src/Extension/Markdownalternate.php`

- [ ] **Step 4: Commit**

```bash
git add src/Extension/Markdownalternate.php
git commit -m "Add opt-in prepareContent() on an isolated dispatcher"
```

---

### Task 3: Wire preparation into the article body

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

- [ ] **Step 2: Lint**

Run: `php -l src/Extension/Markdownalternate.php`
Expected: `No syntax errors detected in src/Extension/Markdownalternate.php`

- [ ] **Step 3: Commit**

```bash
git add src/Extension/Markdownalternate.php
git commit -m "Run shortcode preparation on the article body"
```

---

### Task 4: Wire into category introtexts and enrich the category query

**Files:**
- Modify: `src/Extension/Markdownalternate.php` (`buildCategoryMarkdownResponse()` loop and `loadCategory()`)

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

- [ ] **Step 2: Add columns to the category article query**

Find:

```php
                $db->quoteName('introtext'),
                $db->quoteName('images'),
            ])
```

Replace with:

```php
                $db->quoteName('introtext'),
                $db->quoteName('images'),
                $db->quoteName('catid'),
                $db->quoteName('created'),
                $db->quoteName('language'),
            ])
```

- [ ] **Step 3: Lint**

Run: `php -l src/Extension/Markdownalternate.php`
Expected: `No syntax errors detected in src/Extension/Markdownalternate.php`

- [ ] **Step 4: Commit**

```bash
git add src/Extension/Markdownalternate.php
git commit -m "Run shortcode preparation on category introtexts"
```

---

### Task 5: Bump version and verify

**Files:**
- Modify: `markdownalternate.xml`

- [ ] **Step 1: Bump the version**

Find:

```xml
    <version>1.2.2</version>
```

Replace with:

```xml
    <version>1.3.0</version>
```

- [ ] **Step 2: Validate manifest XML**

Run: `php -r '$d=new DOMDocument(); echo $d->load("markdownalternate.xml")?"XML OK\n":"XML INVALID\n";'`
Expected: `XML OK`

- [ ] **Step 3: Commit**

```bash
git add markdownalternate.xml
git commit -m "Bump to 1.3.0 for opt-in shortcode rendering"
```

- [ ] **Step 4: Manual verification on a live Joomla 6 site**

Run after deploy; each must pass (1 and 3 are the v1 regression guards):

1. Feature **off** (default) → `.md` and `?output=markdown` return **200** Markdown, identical to 1.2.2. No `/login` redirect.
2. Feature **on**, default allow-list, article with `{loadmodule ...}` / `{loadposition ...}` → module renders as Markdown, no literal shortcode left, **200**.
3. Feature **on** on a site with a gating content plugin that is NOT in the allow-list → `.md` still returns **200** (the gating plugin must not fire, no `/login` redirect).
4. Custom fields appear **once**, only in the `## Custom Fields` section.
5. Feature **on**, `shortcode_plugins` emptied → returns un-prepared text, **200**.
6. Category introtexts with allow-listed shortcodes → expanded per article.

---

## Post-implementation

After manual verification, update the vault note `15-extensions/joomill-extensions/markdownalternate.md` confirming the safe rebuild shipped in 1.3.0 and that the v1 redirect did not recur.

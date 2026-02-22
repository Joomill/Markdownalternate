<?php
/*
 *  package: Joomill Markdown Alternate plugin
 *  copyright: Copyright (c) 2026. Jeroen Moolenschot | Joomill
 *  license: GNU General Public License version 3 or later
 *  link: https://www.joomill-extensions.com
 */

namespace Joomill\Plugin\System\Markdownalternate\Extension;

// No direct access.
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

final class Markdownalternate extends CMSPlugin implements SubscriberInterface
{
    /** @var bool */
    private $markdownRequested = false;

    /** @var string */
    private $originalPath = '';

    // -----------------------------------------------------------------------
    // Event subscription
    // -----------------------------------------------------------------------

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => 'onAfterInitialise',
            'onAfterRoute'      => 'onAfterRoute',
            'onBeforeRender'    => 'onBeforeRender',
        ];
    }

    // -----------------------------------------------------------------------
    // Plugin event handlers
    // -----------------------------------------------------------------------

    public function onAfterInitialise(Event $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        if ($app->getInput()->getString('output') === 'markdown') {
            $this->markdownRequested = true;
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (strpos($accept, 'text/markdown') !== false) {
            $this->markdownRequested = true;
        }

        $uri  = Uri::getInstance();
        $path = $uri->getPath();

        if (substr($path, -3) === '.md') {
            $this->markdownRequested = true;
            $this->originalPath      = $path;

            $uri->setPath(substr($path, 0, -3));

            if (isset($_SERVER['REQUEST_URI'])) {
                $_SERVER['REQUEST_URI'] = preg_replace(
                    '/\.md(\?|#|$)/',
                    '$1',
                    $_SERVER['REQUEST_URI']
                );
            }
        }
    }

    public function onAfterRoute(Event $event): void
    {
        if (!$this->markdownRequested) {
            return;
        }

        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $input  = $app->getInput();
        $option = $input->getString('option');
        $view   = $input->getString('view');
        $id     = $input->getInt('id');

        if ($option !== 'com_content' || !in_array($view, ['article', 'category']) || $id < 1) {
            return;
        }

        if ($view === 'article') {
            $article = $this->loadArticle($id);

            if (!$article) {
                return;
            }

            // Debug mode: ?output=markdown&debug=1
            if ($input->getInt('debug') === 1) {
                $this->outputDebugReport($article, $id);
                $app->close();
                return;
            }

            $markdown = $this->buildMarkdownResponse($article);
        } else {
            $category = $this->loadCategory($id);

            if (!$category) {
                return;
            }

            $markdown = $this->buildCategoryMarkdownResponse($category);
        }

        $tokens   = (int) (strlen($markdown) / 4);

        $uri      = Uri::getInstance();
        $baseUrl  = $uri->toString(['scheme', 'host', 'port']);
        $htmlPath = $this->originalPath
            ? substr($this->originalPath, 0, -3)
            : $uri->getPath();

        header('Content-Type: text/markdown; charset=utf-8');
        header('X-Markdown-Tokens: ' . $tokens);
        header('Link: <' . $baseUrl . $htmlPath . '>; rel="canonical"');

        echo $markdown;
        $app->close();
    }

    public function onBeforeRender(Event $event): void
    {
        if ($this->markdownRequested) {
            return;
        }

        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $input  = $app->getInput();
        $option = $input->getString('option');
        $view   = $input->getString('view');
        $id     = $input->getInt('id');

        if ($option !== 'com_content' || !in_array($view, ['article', 'category']) || $id < 1) {
            return;
        }

        if (!$this->params->get('show_link', 1)) {
            return;
        }

        $uri     = Uri::getInstance();
        $current = $uri->toString(['scheme', 'host', 'port', 'path']);
        $mdUrl   = rtrim($current, '/') . '.md';

        $doc = $app->getDocument();
        $doc->addHeadLink($mdUrl, 'alternate', 'rel', ['type' => 'text/markdown']);
    }

    // -----------------------------------------------------------------------
    // Article loading — pure DB, no model, no FieldsHelper
    // -----------------------------------------------------------------------

    /**
     * Load everything we need via direct DB queries.
     *
     * We deliberately avoid the com_content ArticleModel and FieldsHelper here.
     * Both require a fully initialised Joomla document object, which does not
     * exist yet during onAfterRoute — causing "setMetaData() on null" errors.
     *
     * Custom fields are read directly from #__fields + #__fields_values.
     */
    private function loadArticle(int $id): ?object
    {
        $db = Factory::getDbo();

        // --- Main article row ---
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('a.id'),
                $db->quoteName('a.title'),
                $db->quoteName('a.alias'),
                $db->quoteName('a.introtext'),
                $db->quoteName('a.fulltext'),
                $db->quoteName('a.created'),
                $db->quoteName('a.images'),
                $db->quoteName('a.catid'),
                $db->quoteName('a.metadesc'),
                // Model returns author as "author", not "author_name"
                $db->quoteName('u.name',  'author'),
                $db->quoteName('c.title', 'category_title'),
                $db->quoteName('c.alias', 'category_alias'),
            ])
            ->from($db->quoteName('#__content', 'a'))
            ->leftJoin(
                $db->quoteName('#__users', 'u')
                . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('a.created_by')
            )
            ->leftJoin(
                $db->quoteName('#__categories', 'c')
                . ' ON ' . $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid')
            )
            ->where($db->quoteName('a.id')    . ' = ' . (int) $id)
            ->where($db->quoteName('a.state') . ' = 1');

        $db->setQuery($query);
        $article = $db->loadObject();

        if (!$article) {
            return null;
        }

        // Merge intro + full text (same as what the model does via onContentPrepare)
        $sep           = !empty($article->fulltext) ? "\n" : '';
        $article->text = ($article->introtext ?? '') . $sep . ($article->fulltext ?? '');

        // --- Tags ---
        if ($this->params->get('show_tags', 1)) {
            $tagQuery = $db->getQuery(true)
                ->select([$db->quoteName('t.title'), $db->quoteName('t.alias')])
                ->from($db->quoteName('#__tags', 't'))
                ->join(
                    'INNER',
                    $db->quoteName('#__contentitem_tag_map', 'map')
                    . ' ON ' . $db->quoteName('map.tag_id') . ' = ' . $db->quoteName('t.id')
                )
                ->where($db->quoteName('map.content_item_id') . ' = ' . (int) $id)
                ->where($db->quoteName('map.type_alias') . ' = ' . $db->quote('com_content.article'));

            $db->setQuery($tagQuery);
            $article->tags = $db->loadObjectList() ?: [];
        } else {
            $article->tags = [];
        }

        // --- Custom fields ---
        // Read directly from #__fields + #__fields_values.
        // No FieldsHelper, no document dependency, no crashes.
        if ($this->params->get('show_fields', 1)) {
            $article->custom_fields = $this->loadCustomFieldsFromDb($id);
        } else {
            $article->custom_fields = [];
        }

        return $article;
    }

    /**
     * Load custom fields for an article directly from the database.
     *
     * Reads from:
     *  #__fields        — field definitions (name, label, type, params)
     *  #__fields_values — stored values per content item
     *
     * Skips fields with no stored value, unpublished fields, and fields
     * whose assigned group context does not match com_content.article.
     *
     * @param   int  $id  Article ID.
     * @return  array     Array of stdClass with: name, label, type, rawvalue, value.
     */
    private function loadCustomFieldsFromDb(int $id): array
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('f.id'),
                $db->quoteName('f.name'),
                $db->quoteName('f.label'),
                $db->quoteName('f.type'),
                $db->quoteName('f.params'),
                $db->quoteName('fv.value', 'rawvalue'),
            ])
            ->from($db->quoteName('#__fields', 'f'))
            ->join(
                'INNER',
                $db->quoteName('#__fields_values', 'fv')
                . ' ON ' . $db->quoteName('fv.field_id') . ' = ' . $db->quoteName('f.id')
            )
            ->where($db->quoteName('fv.item_id') . ' = ' . (int) $id)
            ->where($db->quoteName('f.context')  . ' = ' . $db->quote('com_content.article'))
            ->where($db->quoteName('f.state')    . ' = 1')
            ->order($db->quoteName('f.ordering') . ' ASC');

        $db->setQuery($query);
        $rows = $db->loadObjectList();

        if (empty($rows)) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            // Skip completely empty values.
            if ($row->rawvalue === '' || $row->rawvalue === null) {
                continue;
            }

            $label = html_entity_decode($row->label ?: $row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Build a human-readable rendered value from rawvalue + field type.
            $value = $this->renderFieldValue($row->rawvalue, $row->type, $row->params ?? '{}', (int) $row->id);

            $result[] = (object) [
                'name'     => $row->name,
                'label'    => $label,
                'type'     => $row->type,
                'rawvalue' => $row->rawvalue,
                'value'    => $value,
            ];
        }

        return $result;
    }

    /**
     * Render a raw field value to a clean, human-readable string.
     *
     * - Simple types (text, textarea, url, email, integer, color, …): strip tags.
     * - Option types (list, radio, checkboxes): resolve keys to labels.
     * - Media/image: return the filename/URL, skip empty arrays.
     * - Subform: render each row using child field labels (looked up from DB),
     *   strip HTML from values, skip image/Array sub-fields.
     *
     * @param   string  $rawvalue  Raw value from #__fields_values.
     * @param   string  $type      Field type string.
     * @param   string  $params    JSON string from #__fields.params.
     * @param   int     $fieldId   Field ID (needed to resolve subform children).
     * @return  string
     */
    private function renderFieldValue(string $rawvalue, string $type, string $params, int $fieldId = 0): string
    {
        // --- Option-based types: resolve stored key(s) to their labels ---
        $optionTypes = ['list', 'radio', 'checkboxes'];

        if (in_array($type, $optionTypes, true)) {
            $decoded = json_decode($params, true);
            $options = $decoded['options'] ?? [];

            $map = [];
            foreach ($options as $opt) {
                if (isset($opt['value'], $opt['name'])) {
                    $map[$opt['value']] = html_entity_decode($opt['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }

            $keys   = json_decode($rawvalue, true);
            $keys   = is_array($keys) ? $keys : [$rawvalue];
            $labels = array_map(static function ($k) use ($map) { return isset($map[$k]) ? $map[$k] : $k; }, $keys);

            return implode(', ', array_filter($labels));
        }

        // --- Media/image: may be a JSON object with a "url" or "imagefile" key ---
        if (in_array($type, ['media', 'image'], true)) {
            $decoded = json_decode($rawvalue, true);

            if (is_array($decoded)) {
                $path = $decoded['url'] ?? $decoded['imagefile'] ?? '';
            } else {
                $path = strip_tags($rawvalue);
            }

            return $this->cleanImageUrl($path, $this->getAbsoluteBaseUrl());
        }

        // --- Subform: render rows using real child field labels ---
        if ($type === 'subform') {
            return $this->renderSubformValue($rawvalue, $fieldId);
        }

        // --- Everything else: strip HTML tags and decode entities ---
        return html_entity_decode(strip_tags($rawvalue), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Render a subform field value into clean, readable text.
     *
     * Subforms store their value as JSON:
     *   {"row0": {"field_name": "value", ...}, "row1": {...}, ...}
     *
     * We look up the child field definitions from #__fields to get proper
     * labels, then format each row. Rows with only one meaningful value are
     * rendered as a simple list. Rows with multiple values are rendered as
     * "Label: value" pairs per row, rows separated by semicolons.
     *
     * Fields of type media/image (whose value is an array or "Array") are
     * skipped entirely — they add no useful text for AI agents.
     *
     * @param   string  $rawvalue  JSON string.
     * @param   int     $fieldId   Parent subform field ID.
     * @return  string
     */
    private function renderSubformValue(string $rawvalue, int $fieldId): string
    {
        $data = json_decode($rawvalue, true);

        if (!is_array($data) || empty($data)) {
            return html_entity_decode(strip_tags($rawvalue), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Normalize: Joomla stores rows as {"row0":{...},"row1":{...}} or as [[...]].
        $rows = array_values($data);

        // If rows are not arrays themselves, treat rawvalue as a flat list.
        if (!is_array($rows[0] ?? null)) {
            return implode(', ', array_map(
                static function ($v) { return html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8'); },
                $rows
            ));
        }

        // Collect all child field names present in the rows.
        $childNames = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $name) {
                $childNames[$name] = true;
            }
        }

        // Look up labels and types for child fields by name.
        $childMeta = $this->loadChildFieldMeta(array_keys($childNames));

        $renderedRows = [];

        foreach ($rows as $row) {
            $parts = [];

            foreach ($row as $fieldName => $value) {
                $meta  = $childMeta[$fieldName] ?? null;
                $ftype = $meta->type ?? 'text';

                // Handle media/image fields which are stored as JSON/array.
                if (is_array($value) && isset($value['imagefile'])) {
                    $src   = $value['imagefile'];
                    $alt   = $value['alt_text'] ?? '';
                    $clean = '![' . $alt . '](' . $this->cleanImageUrl($src, $this->getAbsoluteBaseUrl()) . ')';
                } elseif (trim((string) $value) === 'Array' || (is_array($value) && empty($value))) {
                    // Skip literally "Array" string values or empty arrays.
                    continue;
                } else {
                    // Clean the value (standard text/HTML).
                    $clean = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $clean = trim($clean);
                }

                if ($clean === '') {
                    continue;
                }

                // For single-column subforms, just collect values without labels.
                if (count($childMeta) <= 1) {
                    $parts[] = $clean;
                } else {
                    $label = $meta ? html_entity_decode($meta->label, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $fieldName;

                    // Skip generic field labels like "field23"
                    if (preg_match('/^field\d+$/', $label)) {
                        $parts[] = $clean;
                    } else {
                        $parts[] = $label . ': ' . $clean;
                    }
                }
            }

            if (!empty($parts)) {
                $renderedRows[] = implode(', ', $parts);
            }
        }

        return implode('; ', $renderedRows);
    }

    /**
     * Load label and type for child fields by their name column.
     *
     * Returns an associative array keyed by field name.
     *
     * @param   string[]  $names
     * @return  array<string, object>  Keys: name → stdClass{label, type}
     */
    private function loadChildFieldMeta(array $names): array
    {
        if (empty($names)) {
            return [];
        }

        $db     = Factory::getDbo();
        $quoted = array_map([$db, 'quote'], $names);

        $query = $db->getQuery(true)
            ->select([$db->quoteName('name'), $db->quoteName('label'), $db->quoteName('type')])
            ->from($db->quoteName('#__fields'))
            ->where($db->quoteName('name') . ' IN (' . implode(',', $quoted) . ')');

        $db->setQuery($query);
        $rows = $db->loadObjectList('name');

        return $rows ?: [];
    }

    // -----------------------------------------------------------------------
    // Markdown building
    // -----------------------------------------------------------------------

    /**
     * Render a single custom field as a Markdown block for the body section.
     *
     * Subform rendering strategy:
     *  - Collect ALL non-empty, non-media, non-"Array" values per row.
     *  - 1 meaningful column  → simple bullet list
     *  - 2 meaningful columns → **first value** (bold) + second value on next line
     *  - 3+ columns           → "**Label:** value" per column, blank line between rows
     */
    private function renderFieldAsMarkdown(object $field): string
    {
        if ($field->type !== 'subform') {
            if (in_array($field->type, ['media', 'image'], true) && !empty($field->value)) {
                return '**' . $field->label . ':** ![](' . $field->value . ")\n\n";
            }
            return '**' . $field->label . ':** ' . $field->value . "\n\n";
        }

        $data = json_decode($field->rawvalue, true);

        if (!is_array($data) || empty($data)) {
            return '**' . $field->label . ':** ' . $field->value . "\n\n";
        }

        $rows = array_values($data);

        // Flat (non-associative) rows — simple bullet list.
        if (!is_array($rows[0] ?? null)) {
            $out = '**' . $field->label . ":**\n\n";
            foreach ($rows as $v) {
                $clean = html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $clean = trim($clean);
                if ($clean !== '') {
                    $out .= '- ' . $clean . "\n";
                }
            }
            return $out . "\n";
        }

        // Look up child field metadata (label + type) by field name.
        $childNames = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                foreach (array_keys($row) as $name) {
                    $childNames[$name] = true;
                }
            }
        }
        $childMeta = $this->loadChildFieldMeta(array_keys($childNames));

        $out = '**' . $field->label . ":**\n\n";

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Collect all values from this row, preserving order.
            $colValues = [];
            foreach ($row as $fieldName => $value) {
                $meta  = isset($childMeta[$fieldName]) ? $childMeta[$fieldName] : null;
                $ftype = ($meta && isset($meta->type)) ? $meta->type : 'text';

                // Handle media/image fields which are stored as JSON/array.
                if (is_array($value) && isset($value['imagefile'])) {
                    $src   = $value['imagefile'];
                    $alt   = $value['alt_text'] ?? '';
                    $clean = '![' . $alt . '](' . $this->cleanImageUrl($src, $this->getAbsoluteBaseUrl()) . ')';
                } elseif (trim((string) ($value ?? '')) === 'Array' || (is_array($value) && empty($value))) {
                    // Skip literally "Array" string values or empty arrays.
                    continue;
                } else {
                    // Clean the value (standard text/HTML).
                    $clean = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $clean = trim($clean);
                }

                if ($clean === '') {
                    continue;
                }

                $label = ($meta && !empty($meta->label))
                    ? html_entity_decode($meta->label, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    : $fieldName;

                // Mark labels like "field23" as generic so they can be hidden.
                $isGeneric = (bool) preg_match('/^field\d+$/', $label);

                $colValues[] = ['label' => $label, 'value' => $clean, 'isGeneric' => $isGeneric];
            }

            if (empty($colValues)) {
                continue;
            }

            $count = count($colValues);

            if ($count === 1) {
                // Single meaningful column → bullet list entry.
                $out .= '- ' . $colValues[0]['value'] . "\n";
            } elseif ($count === 2) {
                // Two columns → **first** bold as heading, second as body text.
                $out .= '**' . $colValues[0]['value'] . "**\n";
                $out .= $colValues[1]['value'] . "\n\n";
            } else {
                // Three or more columns → "**Label:** value" per column.
                foreach ($colValues as $col) {
                    if ($col['isGeneric'] ?? false) {
                        $out .= $col['value'] . "\n";
                    } else {
                        $out .= '**' . $col['label'] . ':** ' . $col['value'] . "\n";
                    }
                }
                $out .= "\n";
            }
        }

        return $out . "\n";
    }


    private function buildCategoryMarkdownResponse(object $category): string
    {
        $baseUrl = $this->getAbsoluteBaseUrl();
        $title   = html_entity_decode($category->title ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // ---- YAML Frontmatter ----
        $fm  = "---\n";
        $fm .= 'title: "' . addslashes($title) . "\"\n";

        if ($this->params->get('show_description', 1) && !empty($category->metadesc)) {
            $fm .= 'description: "' . addslashes($category->metadesc) . "\"\n";
        }

        // Category Image
        if ($this->params->get('show_images', 1)) {
            $params = json_decode($category->params ?? '{}', false);
            if (!empty($params->image)) {
                $catImage = $this->cleanImageUrl($params->image, $baseUrl);
                $fm .= 'image: "' . $catImage . "\"\n";
            }
        }

        $fm .= "---\n\n";

        // ---- Body ----
        $body = '# ' . $title . "\n\n";

        if ($this->params->get('show_description', 1) && !empty($category->description)) {
            $body .= $this->htmlToMarkdown($category->description) . "\n\n";
        }

        if (!empty($category->articles)) {
            foreach ($category->articles as $article) {
                $articleTitle = html_entity_decode($article->title ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $body .= '## ' . $articleTitle . "\n\n";

                // Intro Image
                if ($this->params->get('show_images', 1)) {
                    $images = json_decode($article->images ?? '{}', false);
                    if (!empty($images->image_intro)) {
                        $introImage = $this->cleanImageUrl($images->image_intro, $baseUrl);
                        $body .= '![' . addslashes($articleTitle) . '](' . $introImage . ")\n\n";
                    }
                }

                // Intro Text
                if (!empty($article->introtext)) {
                    $body .= $this->htmlToMarkdown($article->introtext) . "\n\n";
                }

                // Link to full article
                $articleUrl = rtrim($baseUrl, '/') . '/' . ($category->alias ?? '') . '/' . ($article->alias ?? '') . '.md';
                $body .= '[Lees meer...](' . $articleUrl . ")\n\n";
            }
        }

        return $fm . $body;
    }

    private function loadCategory(int $id): ?object
    {
        $db = Factory::getDbo();

        // --- Category row ---
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'),
                $db->quoteName('title'),
                $db->quoteName('alias'),
                $db->quoteName('description'),
                $db->quoteName('metadesc'),
                $db->quoteName('params'),
            ])
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('id')        . ' = ' . (int) $id)
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
            ->where($db->quoteName('published') . ' = 1');

        $db->setQuery($query);
        $category = $db->loadObject();

        if (!$category) {
            return null;
        }

        // --- Articles in category ---
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'),
                $db->quoteName('title'),
                $db->quoteName('alias'),
                $db->quoteName('introtext'),
                $db->quoteName('images'),
            ])
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('catid') . ' = ' . (int) $id)
            ->where($db->quoteName('state') . ' = 1')
            ->order($db->quoteName('ordering') . ' ASC');

        $db->setQuery($query);
        $category->articles = $db->loadObjectList() ?: [];

        return $category;
    }

    private function buildMarkdownResponse(object $article): string
    {
        $baseUrl  = $this->getAbsoluteBaseUrl();
        $title    = html_entity_decode($article->title    ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $author   = html_entity_decode($article->author   ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $catTitle = html_entity_decode($article->category_title ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // ---- YAML Frontmatter ----
        $fm  = "---\n";
        $fm .= 'title: "' . addslashes($title) . "\"\n";

        if ($this->params->get('show_date', 1)) {
            $fm .= 'date: ' . date('Y-m-d', strtotime($article->created)) . "\n";
        }

        if ($this->params->get('show_description', 1) && !empty($article->metadesc)) {
            $fm .= 'description: "' . addslashes($article->metadesc) . "\"\n";
        }

        if ($this->params->get('show_author', 1) && $author !== '') {
            $fm .= 'author: "' . addslashes($author) . "\"\n";
        }

        // Images.
        if ($this->params->get('show_images', 1)) {
            $images = json_decode($article->images ?? '{}', false);

            if (!empty($images->image_intro)) {
                $introImage = $this->cleanImageUrl($images->image_intro, $baseUrl);
                $fm .= 'intro_image: "' . $introImage . "\"\n";
            }

            if (!empty($images->image_fulltext)) {
                $fulltextImage = $this->cleanImageUrl($images->image_fulltext, $baseUrl);
                $fm .= 'fulltext_image: "' . $fulltextImage . "\"\n";
            }
        }

        // Category.
        if ($this->params->get('show_category', 1) && $catTitle !== '') {
            $catUrl = rtrim($baseUrl, '/') . '/' . ($article->category_alias ?? '') . '.md';
            $fm .= "categories:\n";
            $fm .= '  - name: "' . addslashes($catTitle) . "\"\n";
            $fm .= '    url: "' . $catUrl . "\"\n";
        }

        // Tags.
        if ($this->params->get('show_tags', 1) && !empty($article->tags)) {
            $fm .= "tags:\n";
            foreach ($article->tags as $tag) {
                $tagTitle = html_entity_decode($tag->title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $tagUrl   = rtrim($baseUrl, '/') . '/tags/' . $tag->alias . '.md';
                $fm .= '  - name: "' . addslashes($tagTitle) . "\"\n";
                $fm .= '    url: "' . $tagUrl . "\"\n";
            }
        }

        $fm .= "---\n\n";

        // ---- Body ----
        $body = '# ' . $title . "\n\n";

        if ($this->params->get('show_images', 1)) {
            $images = json_decode($article->images ?? '{}', false);
            $mainImage = !empty($images->image_fulltext) ? $images->image_fulltext : ($images->image_intro ?? '');
            if ($mainImage !== '') {
                $body .= '![' . addslashes($title) . '](' . $this->cleanImageUrl($mainImage, $baseUrl) . ")\n\n";
            }
        }

        $body .= $this->htmlToMarkdown($article->text ?? '');

        // Custom fields as readable section at the end.
        if ($this->params->get('show_fields', 1) && !empty($article->custom_fields)) {
            $body .= "\n\n## Custom Fields\n\n";
            foreach ($article->custom_fields as $field) {
                $body .= $this->renderFieldAsMarkdown($field);
            }
        }

        return $fm . $body;
    }

    // -----------------------------------------------------------------------
    // Debug helper
    // -----------------------------------------------------------------------

    private function outputDebugReport(object $article, int $id): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');

        $out   = [];
        $out[] = '=== Markdown Alternate — Debug Report v1.4 ===';
        $out[] = 'Article ID : ' . $id;
        $out[] = 'Title      : ' . ($article->title ?? '(empty)');
        $out[] = 'Text length: ' . strlen($article->text ?? '') . ' bytes';
        $out[] = 'Author     : ' . ($article->author ?? '(empty)');
        $out[] = '';

        $out[] = '--- Custom fields from #__fields + #__fields_values ---';
        if (empty($article->custom_fields)) {
            $db    = Factory::getDbo();

            // Check if any fields exist for this context at all.
            $q = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__fields'))
                ->where($db->quoteName('context') . ' = ' . $db->quote('com_content.article'))
                ->where($db->quoteName('state')   . ' = 1');
            $db->setQuery($q);
            $fieldCount = (int) $db->loadResult();

            $out[] = 'Fields for com_content.article in #__fields (state=1): ' . $fieldCount;

            // Check if any values exist for this article.
            $q2 = $db->getQuery(true)
                ->select(['fv.field_id', 'fv.value', 'f.name', 'f.label', 'f.context', 'f.state'])
                ->from($db->quoteName('#__fields_values', 'fv'))
                ->leftJoin(
                    $db->quoteName('#__fields', 'f')
                    . ' ON ' . $db->quoteName('f.id') . ' = ' . $db->quoteName('fv.field_id')
                )
                ->where($db->quoteName('fv.item_id') . ' = ' . (int) $id);
            $db->setQuery($q2);
            $values = $db->loadObjectList();

            if (empty($values)) {
                $out[] = 'No rows in #__fields_values for article id=' . $id;
                $out[] = 'Either no fields are assigned to this article, or no values have been saved.';
            } else {
                $out[] = 'Rows in #__fields_values for article id=' . $id . ':';
                foreach ($values as $v) {
                    $out[] = sprintf(
                        '  field_id=%s  name="%s"  label="%s"  context="%s"  state=%s  value=%s',
                        $v->field_id,
                        $v->name    ?? '(no field row)',
                        $v->label   ?? '?',
                        $v->context ?? '?',
                        $v->state   ?? '?',
                        json_encode($v->value)
                    );
                }
            }
        } else {
            $out[] = count($article->custom_fields) . ' field(s) loaded:';
            foreach ($article->custom_fields as $f) {
                $out[] = sprintf(
                    '  name="%s"  type="%s"  label="%s"  rawvalue=%s  rendered="%s"',
                    $f->name,
                    $f->type,
                    $f->label,
                    json_encode($f->rawvalue),
                    $f->value
                );
            }
        }

        $out[] = '';
        $out[] = '--- All properties on $article ---';
        $out[] = implode(', ', array_keys((array) $article));
        $out[] = '';
        $out[] = '=== End of report ===';

        echo implode("\n", $out);
    }

    // -----------------------------------------------------------------------
    // YAML scalar helper
    // -----------------------------------------------------------------------

    private function getAbsoluteBaseUrl(): string
    {
        $uri    = Uri::getInstance();
        $scheme = $uri->getScheme();
        $host   = $uri->getHost();

        if ($scheme && $host) {
            $port = $uri->getPort();
            $port = $port ? ':' . $port : '';

            return $scheme . '://' . $host . $port . Uri::root(true);
        }

        // Fallback to Uri::root(false) which should be absolute in web context.
        $root = Uri::root(false);

        if (strpos($root, 'http') === 0) {
            return $root;
        }

        // Final fallback for environments where Uri::root() might be relative.
        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host . Uri::root(true);
    }

    /**
     * Clean an image path:
     *  - Make relative paths absolute.
     *  - Strip everything from '#' onward (e.g. '#joomlaImage://…').
     */
    private function cleanImageUrl(string $path, string $baseUrl = ''): string
    {
        // Strip the #joomlaImage:// fragment and anything after it.
        if (($pos = strpos($path, '#')) !== false) {
            $path = substr($path, 0, $pos);
        }

        $path = trim($path);

        // Make relative paths absolute.
        if ($path !== '' && substr($path, 0, 4) !== 'http' && substr($path, 0, 2) !== '//') {
            if ($baseUrl === '') {
                $baseUrl = $this->getAbsoluteBaseUrl();
            }
            $path = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        }

        return $path;
    }

    private function yamlScalar(string $value): string
    {
        $needsQuoting = ['"', "'", ':', '#', '{', '}', '[', ']', ',', '&', '*', '?', '|', '-', '<', '>', '=', '!', '%', '@', '`', "\n", "\r"];

        foreach ($needsQuoting as $char) {
            if (strpos($value, $char) !== false) {
                return '"' . str_replace('"', '\\"', $value) . '"';
            }
        }

        return $value !== '' ? $value : '""';
    }

    // -----------------------------------------------------------------------
    // HTML → Markdown converter
    // -----------------------------------------------------------------------

    private function htmlToMarkdown(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');

        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
            LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);

        if (!$body) {
            return strip_tags($html);
        }

        $markdown = $this->convertChildren($body);
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown);

        return trim($markdown) . "\n";
    }

    private function convertChildren(\DOMNode $node): string
    {
        $output = '';

        foreach ($node->childNodes as $child) {
            $output .= $this->convertNode($child);
        }

        return $output;
    }

    private function convertNode(\DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return preg_replace('/\s+/', ' ', $node->textContent);
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag      = strtolower($node->nodeName);
        $children = $this->convertChildren($node);

        switch ($tag) {
            case 'h1': return "\n# "      . trim($children) . "\n\n";
            case 'h2': return "\n## "     . trim($children) . "\n\n";
            case 'h3': return "\n### "    . trim($children) . "\n\n";
            case 'h4': return "\n#### "   . trim($children) . "\n\n";
            case 'h5': return "\n##### "  . trim($children) . "\n\n";
            case 'h6': return "\n###### " . trim($children) . "\n\n";

            case 'p':
                $inner = trim($children);
                return $inner !== '' ? $inner . "\n\n" : '';

            case 'br': return "  \n";
            case 'hr': return "\n---\n\n";

            case 'blockquote':
                $inner  = trim($children);
                $lines  = explode("\n", $inner);
                $quoted = implode("\n", array_map(static function ($l) { return '> ' . $l; }, $lines));
                return "\n" . $quoted . "\n\n";

            case 'pre':
                return $this->convertPreBlock($node);

            case 'strong':
            case 'b':
                $inner = trim($children);
                return $inner !== '' ? '**' . $inner . '**' : '';

            case 'em':
            case 'i':
                $inner = trim($children);
                return $inner !== '' ? '*' . $inner . '*' : '';

            case 'code':
                if ($node->parentNode && strtolower($node->parentNode->nodeName) === 'pre') {
                    return $children;
                }
                return '`' . $node->textContent . '`';

            case 'a':
                $href = $node->getAttribute('href');
                $text = trim($children);
                if ($href === '') {
                    return $text;
                }
                if ($text === '') {
                    $text = $href;
                }

                // Make relative links absolute.
                if (substr($href, 0, 4) !== 'http' && substr($href, 0, 2) !== '//' && substr($href, 0, 1) !== '#' && substr($href, 0, 7) !== 'mailto:') {
                    $href = rtrim($this->getAbsoluteBaseUrl(), '/') . '/' . ltrim($href, '/');
                }

                return '[' . $text . '](' . $href . ')';

            case 'img':
                $src   = $node->getAttribute('src');
                $alt   = $node->getAttribute('alt');
                $title = $node->getAttribute('title');
                $src   = $this->cleanImageUrl($src, $this->getAbsoluteBaseUrl());
                $md    = '![' . $alt . '](' . $src;
                if ($title !== '') {
                    $md .= ' "' . str_replace('"', '\\"', $title) . '"';
                }
                return $md . ')';

            case 'ul': return "\n" . $this->convertList($node, false) . "\n";
            case 'ol': return "\n" . $this->convertList($node, true)  . "\n";
            case 'li': return trim($children) . "\n";

            case 'table':   return "\n" . $this->convertTable($node) . "\n";
            case 'thead':
            case 'tbody':
            case 'tfoot':
            case 'caption':
            case 'tr':
            case 'th':
            case 'td':
                return $children;

            case 'div':
            case 'section':
            case 'article':
            case 'main':
            case 'header':
            case 'footer':
            case 'aside':
            case 'nav':
            case 'figure':
            case 'figcaption':
                $inner = trim($children);
                return $inner !== '' ? $inner . "\n\n" : '';

            case 'span':
            case 'abbr':
            case 'cite':
            case 'mark':
            case 'small':
            case 'sub':
            case 'sup':
            case 'time':
            case 'label':
                return $children;

            case 'del':
            case 's':
                return '~~' . trim($children) . '~~';

            case 'script':
            case 'style':
            case 'noscript':
            case 'iframe':
            case 'button':
            case 'input':
            case 'select':
            case 'textarea':
            case 'form':
            case 'svg':
                return '';

            default:
                return $children;
        }
    }

    private function convertPreBlock(\DOMElement $pre): string
    {
        $lang     = '';
        $codeNode = null;

        foreach ($pre->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'code') {
                $codeNode = $child;
                break;
            }
        }

        if ($codeNode) {
            $class = $codeNode->getAttribute('class');
            if (preg_match('/(?:language-|lang-)(\w+)/', $class, $matches)) {
                $lang = $matches[1];
            }
            $code = $codeNode->textContent;
        } else {
            $code = $pre->textContent;
        }

        return "\n```" . $lang . "\n" . $code . "```\n\n";
    }

    private function convertList(\DOMElement $list, bool $ordered): string
    {
        $output  = '';
        $counter = 1;

        foreach ($list->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE || strtolower($child->nodeName) !== 'li') {
                continue;
            }

            $text   = trim($this->convertChildren($child));
            $prefix = $ordered ? ($counter . '. ') : '- ';
            $output .= $prefix . $text . "\n";
            $counter++;
        }

        return $output;
    }

    private function convertTable(\DOMElement $table): string
    {
        $headers = [];
        $rows    = [];
        $allRows = $table->getElementsByTagName('tr');

        if ($allRows->length === 0) return '';

        $firstRow = $allRows->item(0);
        $isHeader = false;

        if ($firstRow->parentNode && strtolower($firstRow->parentNode->nodeName) === 'thead') {
            $isHeader = true;
        } else {
            foreach ($firstRow->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && strtolower($cell->nodeName) === 'th') {
                    $isHeader = true;
                    break;
                }
            }
        }

        if ($isHeader) {
            foreach ($firstRow->childNodes as $cell) {
                if ($cell->nodeType !== XML_ELEMENT_NODE) continue;
                $headers[] = trim($this->convertChildren($cell));
            }
            for ($i = 1; $i < $allRows->length; $i++) {
                $rows[] = $this->extractTableRow($allRows->item($i));
            }
        } else {
            for ($i = 0; $i < $allRows->length; $i++) {
                $rows[] = $this->extractTableRow($allRows->item($i));
            }
            $cols    = !empty($rows) ? count($rows[0]) : 0;
            $headers = array_fill(0, $cols, '');
        }

        if (empty($headers) && empty($rows)) return '';

        $colCount  = count($headers);
        $separator = array_fill(0, $colCount, '---');
        $lines     = ['| ' . implode(' | ', $headers) . ' |', '| ' . implode(' | ', $separator) . ' |'];

        foreach ($rows as $row) {
            while (count($row) < $colCount) $row[] = '';
            $row     = array_slice($row, 0, $colCount);
            $lines[] = '| ' . implode(' | ', $row) . ' |';
        }

        return implode("\n", $lines) . "\n";
    }

    private function extractTableRow(\DOMElement $row): array
    {
        $cells = [];

        foreach ($row->childNodes as $cell) {
            if ($cell->nodeType !== XML_ELEMENT_NODE) continue;
            $tag = strtolower($cell->nodeName);
            if ($tag === 'td' || $tag === 'th') {
                $cells[] = str_replace('|', '\\|', trim($this->convertChildren($cell)));
            }
        }

        return $cells;
    }
}

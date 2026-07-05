<?php

namespace gerry3010\mcp\tools;

use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;

/**
 * Section & entry-type management (schema-write, admin + confirm).
 *
 * For anything these helpers don't cover, the project_config tools give
 * lower-level control over the full structural config.
 */
class StructureTool
{
    /**
     * Create or update an entry type:
     *   { handle, name, hasTitleField?, titleFormat?, icon?, color?, fields?[] }
     * `fields` is an array of existing field handles placed in a single tab.
     */
    public static function saveEntryType(array $args): array
    {
        Support::requireAdmin();
        $handle = $args['handle'] ?? null;
        if (!$handle) {
            throw new \RuntimeException('save_entry_type requires "handle"');
        }

        $entries = Craft::$app->getEntries();
        $existing = $entries->getEntryTypeByHandle($handle);
        $et = $existing ?? new EntryType();
        $et->handle = $handle;
        $et->name = $args['name'] ?? $et->name ?? $handle;
        if (array_key_exists('hasTitleField', $args)) {
            $et->hasTitleField = (bool)$args['hasTitleField'];
        } elseif (!$existing) {
            // Match the CP default: new entry types show the Title field.
            $et->hasTitleField = true;
        }
        if (array_key_exists('titleFormat', $args)) {
            $et->titleFormat = $args['titleFormat'];
        }

        // Build a field layout when fields are given, or for any new entry type
        // (so the Title field element is present when hasTitleField is on).
        if ((array_key_exists('fields', $args) && is_array($args['fields'])) || !$existing) {
            $et->setFieldLayout(self::buildLayout(Entry::class, $args['fields'] ?? [], (bool)$et->hasTitleField));
        }

        if (!$entries->saveEntryType($et)) {
            throw new ValidationException("Failed to save entry type '{$handle}'", $et->getErrors());
        }
        return ['handle' => $et->handle, 'uid' => $et->uid, 'saved' => true];
    }

    public static function deleteEntryType(array $args): array
    {
        Support::requireAdmin();
        $handle = $args['handle'] ?? null;
        $et = $handle ? Craft::$app->getEntries()->getEntryTypeByHandle($handle) : null;
        if (!$et) {
            throw new \RuntimeException("Entry type not found: {$handle}");
        }
        Craft::$app->getEntries()->deleteEntryType($et);
        return ['handle' => $handle, 'deleted' => true];
    }

    /**
     * Create or update a section:
     *   { handle, name, type(single|channel|structure), entryTypes[]?,
     *     hasUrls?, uriFormat?, template?, enableVersioning? }
     * Site settings are applied to every site (uriFormat/template shared).
     */
    public static function saveSection(array $args): array
    {
        Support::requireAdmin();
        $handle = $args['handle'] ?? null;
        if (!$handle) {
            throw new \RuntimeException('save_section requires "handle"');
        }

        $entries = Craft::$app->getEntries();
        $section = $entries->getSectionByHandle($handle) ?? new Section();
        $section->handle = $handle;
        $section->name = $args['name'] ?? $section->name ?? $handle;
        $section->type = $args['type'] ?? $section->type ?? Section::TYPE_CHANNEL;
        if (array_key_exists('enableVersioning', $args)) {
            $section->enableVersioning = (bool)$args['enableVersioning'];
        }

        // Entry types: resolve handles to entry-type models.
        if (array_key_exists('entryTypes', $args) && is_array($args['entryTypes'])) {
            $ets = [];
            foreach ($args['entryTypes'] as $etHandle) {
                $et = $entries->getEntryTypeByHandle($etHandle);
                if (!$et) {
                    throw new \RuntimeException("Entry type not found: {$etHandle}");
                }
                $ets[] = $et;
            }
            $section->setEntryTypes($ets);
        }

        // Site settings for every site.
        $hasUrls = (bool)($args['hasUrls'] ?? false);
        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $s = new Section_SiteSettings();
            $s->siteId = $site->id;
            $s->hasUrls = $hasUrls;
            $s->uriFormat = $hasUrls ? ($args['uriFormat'] ?? '{slug}') : null;
            $s->template = $hasUrls ? ($args['template'] ?? null) : null;
            $s->enabledByDefault = true;
            $siteSettings[$site->id] = $s;
        }
        $section->setSiteSettings($siteSettings);

        if (!$entries->saveSection($section)) {
            throw new ValidationException("Failed to save section '{$handle}'", $section->getErrors());
        }
        return ['handle' => $section->handle, 'uid' => $section->uid, 'saved' => true];
    }

    public static function deleteSection(array $args): array
    {
        Support::requireAdmin();
        $handle = $args['handle'] ?? null;
        $section = $handle ? Craft::$app->getEntries()->getSectionByHandle($handle) : null;
        if (!$section) {
            throw new \RuntimeException("Section not found: {$handle}");
        }
        if (!Craft::$app->getEntries()->deleteSection($section)) {
            throw new \RuntimeException("Failed to delete section '{$handle}'");
        }
        return ['handle' => $handle, 'deleted' => true];
    }

    /**
     * Build a single-tab field layout from a list of existing field handles.
     */
    private static function buildLayout(string $elementType, array $fieldHandles, bool $includeTitle = false): FieldLayout
    {
        $layout = new FieldLayout();
        $layout->type = $elementType;

        $tab = new FieldLayoutTab();
        $tab->name = 'Content';
        // The tab needs its owning layout before elements/config are read.
        $tab->setLayout($layout);

        $elements = [];
        // In Craft 5 the entry title is a field-layout element; include it so
        // titles are editable/persisted when the entry type shows a title.
        if ($includeTitle && $elementType === Entry::class) {
            $elements[] = new EntryTitleField();
        }
        foreach ($fieldHandles as $entry) {
            $handle = is_array($entry) ? ($entry['handle'] ?? null) : $entry;
            $required = is_array($entry) ? (bool)($entry['required'] ?? false) : false;
            $field = $handle ? Craft::$app->getFields()->getFieldByHandle($handle) : null;
            if (!$field) {
                throw new \RuntimeException("Field not found: {$handle}");
            }
            $ce = new CustomField($field);
            $ce->required = $required;
            $elements[] = $ce;
        }

        $tab->setElements($elements);
        $layout->setTabs([$tab]);

        return $layout;
    }
}

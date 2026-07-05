<?php

namespace gerry3010\mcp\tools;

use Craft;
use craft\elements\Entry;

/**
 * list_sections and entry CRUD (list/get/create/update/delete).
 *
 * All writes go directly to the live (canonical) element — no drafts. Each
 * write is checked against the token's Craft user permissions
 * (viewEntries/saveEntries/deleteEntries:<sectionUid>); admins bypass.
 */
class EntriesTool
{
    public static function listSections(array $args): array
    {
        $out = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $out[] = [
                'handle' => $section->handle,
                'name' => $section->name,
                'type' => $section->type,
                'entryTypes' => array_map(
                    static fn($et) => ['handle' => $et->handle, 'name' => $et->name],
                    $section->getEntryTypes()
                ),
            ];
        }
        return $out;
    }

    public static function listEntries(array $args): array
    {
        $section = $args['section'] ?? null;
        if (!$section) {
            throw new \RuntimeException('list_entries requires "section"');
        }

        $query = Entry::find()->section($section);

        if (!empty($args['entryType'])) {
            $query->type($args['entryType']);
        }
        if (!empty($args['search'])) {
            $query->search($args['search']);
        }

        $status = $args['status'] ?? 'all';
        $query->status($status === 'all' ? null : $status);

        $query->limit((int)($args['limit'] ?? 50));
        $query->offset((int)($args['offset'] ?? 0));
        $query->orderBy('dateUpdated DESC');

        $entries = array_map(
            static fn(Entry $e) => Support::serialize($e, false),
            $query->all()
        );

        return [
            'section' => $section,
            'count' => count($entries),
            'entries' => $entries,
        ];
    }

    public static function getEntry(array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $entry = Entry::find()->id($id)->status(null)->one();
        if (!$entry) {
            throw new \RuntimeException("Entry {$id} not found");
        }
        $includeBlocks = $args['includeBlocks'] ?? true;
        return Support::serialize($entry, (bool)$includeBlocks);
    }

    public static function createEntry(array $args): array
    {
        $sectionHandle = $args['section'] ?? null;
        if (!$sectionHandle) {
            throw new \RuntimeException('create_entry requires "section"');
        }
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        if (!$section) {
            throw new \RuntimeException("Section not found: {$sectionHandle}");
        }
        Support::requirePermission("saveEntries:{$section->uid}");

        $entryTypes = $section->getEntryTypes();
        $entryType = null;
        if (!empty($args['entryType'])) {
            foreach ($entryTypes as $et) {
                if ($et->handle === $args['entryType']) {
                    $entryType = $et;
                    break;
                }
            }
            if (!$entryType) {
                throw new \RuntimeException("Entry type '{$args['entryType']}' not in section '{$sectionHandle}'");
            }
        } else {
            $entryType = $entryTypes[0];
        }

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;

        self::applyProps($entry, $args);

        Support::save($entry, 'entry');
        return Support::serialize($entry, false);
    }

    public static function updateEntry(array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $entry = Entry::find()->id($id)->status(null)->one();
        if (!$entry) {
            throw new \RuntimeException("Entry {$id} not found");
        }
        Support::requirePermission("saveEntries:{$entry->getSection()->uid}");

        self::applyProps($entry, $args);

        Support::save($entry, 'entry');
        return Support::serialize($entry, false);
    }

    public static function deleteEntry(array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $entry = Entry::find()->id($id)->status(null)->one();
        if (!$entry) {
            throw new \RuntimeException("Entry {$id} not found");
        }
        Support::requirePermission("deleteEntries:{$entry->getSection()->uid}");
        if (!Craft::$app->getElements()->deleteElement($entry)) {
            throw new ValidationException("Failed to delete entry {$id}", $entry->getErrors());
        }
        return ['id' => $id, 'deleted' => true];
    }

    /**
     * Apply title/slug/enabled/authorId and the generic fields map to an entry.
     */
    private static function applyProps(Entry $entry, array $args): void
    {
        if (array_key_exists('title', $args) && $args['title'] !== null) {
            $entry->title = $args['title'];
        }
        if (array_key_exists('slug', $args) && $args['slug'] !== null) {
            $entry->slug = $args['slug'];
        }
        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $entry->enabled = (bool)$args['enabled'];
        }
        if (!empty($args['authorId'])) {
            $entry->setAuthorId((int)$args['authorId']);
        }
        if (!empty($args['fields']) && is_array($args['fields'])) {
            foreach ($args['fields'] as $handle => $value) {
                $entry->setFieldValue($handle, $value);
            }
        }
    }
}

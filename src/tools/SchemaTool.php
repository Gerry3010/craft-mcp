<?php

namespace gerry3010\mcp\tools;

use Craft;

/**
 * describe_content_model — dynamic dump of the content model so the MCP client
 * never needs hardcoded knowledge of sections, entry types, fields, or block
 * types. Also lists sites, volumes, category/tag groups and user groups so an
 * agent can orient itself.
 */
class SchemaTool
{
    public static function describe(array $args): array
    {
        $sectionFilter = $args['section'] ?? null;
        $entryTypeFilter = $args['entryType'] ?? null;

        $sections = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($sectionFilter !== null && $section->handle !== $sectionFilter) {
                continue;
            }

            $entryTypes = [];
            foreach ($section->getEntryTypes() as $entryType) {
                if ($entryTypeFilter !== null && $entryType->handle !== $entryTypeFilter) {
                    continue;
                }
                $entryTypes[] = [
                    'handle' => $entryType->handle,
                    'name' => $entryType->name,
                    'hasTitleField' => $entryType->hasTitleField,
                    'fields' => Support::describeLayout($entryType->getFieldLayout()),
                ];
            }

            $sections[] = [
                'handle' => $section->handle,
                'name' => $section->name,
                'type' => $section->type,
                'entryTypes' => $entryTypes,
            ];
        }

        $out = ['sections' => $sections];

        // Only dump the rest of the model when not scoping to a section.
        if ($sectionFilter === null) {
            $globals = [];
            foreach (Craft::$app->getGlobals()->getAllSets() as $set) {
                $globals[] = [
                    'handle' => $set->handle,
                    'name' => $set->name,
                    'id' => $set->id,
                    'fields' => Support::describeLayout($set->getFieldLayout()),
                ];
            }
            $out['globals'] = $globals;

            $out['categoryGroups'] = array_map(
                static fn($g) => ['handle' => $g->handle, 'name' => $g->name],
                Craft::$app->getCategories()->getAllGroups()
            );
            $out['tagGroups'] = array_map(
                static fn($g) => ['handle' => $g->handle, 'name' => $g->name],
                Craft::$app->getTags()->getAllTagGroups()
            );
            $out['volumes'] = array_map(
                static fn($v) => ['handle' => $v->handle, 'name' => $v->name],
                Craft::$app->getVolumes()->getAllVolumes()
            );
            $out['sites'] = array_map(
                static fn($s) => ['handle' => $s->handle, 'name' => $s->name, 'primary' => $s->primary],
                Craft::$app->getSites()->getAllSites()
            );

            $out['note'] = 'Content-builder fields are Matrix fields; their blocks are nested entries. '
                . 'Manage them with the block tools using ownerId=<owner element id> and field=<matrix field handle>. '
                . 'Nested Matrix (a block inside a block): ownerId=<parent block id>, field=<nested matrix handle>. '
                . 'Global-set matrices: ownerId=<global set id> (see list_globals), field=<matrix handle>.';
        }

        return $out;
    }
}

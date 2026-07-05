<?php

namespace gerry3010\mcp\tools;

use Craft;

/**
 * Global set tools — list, read and update global sets.
 *
 * A global set's Matrix sub-fields are managed with the block tools:
 * ownerId = the set's id (from list_globals), field = the Matrix handle.
 */
class GlobalsTool
{
    public static function listGlobals(array $args): array
    {
        return array_map(
            static fn($set) => ['handle' => $set->handle, 'name' => $set->name, 'id' => $set->id],
            Craft::$app->getGlobals()->getAllSets()
        );
    }

    public static function getGlobals(array $args): array
    {
        return Support::serialize(self::set($args), true);
    }

    public static function updateGlobals(array $args): array
    {
        $set = self::set($args);
        Support::requirePermission("editGlobalSet:{$set->uid}");
        $fields = $args['fields'] ?? [];
        if (!is_array($fields)) {
            throw new \RuntimeException('update_globals "fields" must be an object');
        }
        foreach ($fields as $handle => $value) {
            $set->setFieldValue($handle, $value);
        }
        Support::save($set, 'global set');
        return Support::serialize($set, true);
    }

    private static function set(array $args)
    {
        $handle = $args['handle'] ?? null;
        if (!$handle) {
            throw new \RuntimeException('global tools require "handle"');
        }
        $set = Craft::$app->getGlobals()->getSetByHandle($handle);
        if (!$set) {
            throw new \RuntimeException("Global set not found: {$handle}");
        }
        return $set;
    }
}

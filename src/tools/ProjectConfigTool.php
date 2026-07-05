<?php

namespace gerry3010\mcp\tools;

use Craft;

/**
 * Project-config access (system-write for writes). This is the low-level,
 * fully generic structural-control surface: almost every structural change in
 * Craft ultimately lives in project config.
 *
 * Writes require `allowAdminChanges` to be enabled and an admin Craft user, and
 * are gated behind confirm at the server level. Use project_config_get as the
 * dry-run/diff step before a project_config_set.
 */
class ProjectConfigTool
{
    public static function get(array $args): array
    {
        $path = $args['path'] ?? null;
        $value = Craft::$app->getProjectConfig()->get($path);
        return ['path' => $path, 'value' => $value];
    }

    public static function set(array $args): array
    {
        Support::requireAdmin();
        self::assertAdminChangesAllowed();

        $path = $args['path'] ?? null;
        if (!$path || !is_string($path)) {
            throw new \RuntimeException('project_config_set requires a string "path"');
        }
        if (!array_key_exists('value', $args)) {
            throw new \RuntimeException('project_config_set requires "value" (use null to remove)');
        }

        $before = Craft::$app->getProjectConfig()->get($path);
        // Setting the value schedules and applies the relevant change handlers.
        Craft::$app->getProjectConfig()->set($path, $args['value'], $args['message'] ?? "MCP: set {$path}");

        return [
            'path' => $path,
            'before' => $before,
            'after' => Craft::$app->getProjectConfig()->get($path),
            'applied' => true,
        ];
    }

    /**
     * Re-apply pending external (YAML) changes — the equivalent of `craft up` /
     * project-config/apply.
     */
    public static function apply(array $args): array
    {
        Support::requireAdmin();
        self::assertAdminChangesAllowed();
        Craft::$app->getProjectConfig()->applyExternalChanges();
        return ['applied' => true];
    }

    private static function assertAdminChangesAllowed(): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new \RuntimeException(
                'Project-config writes are disabled: `allowAdminChanges` is false in this environment.'
            );
        }
    }
}

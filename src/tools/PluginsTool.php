<?php

namespace gerry3010\mcp\tools;

use Craft;

/**
 * Plugin management (system-write, admin). Note: install only works for plugin
 * packages that are already present via Composer — this does not run composer.
 */
class PluginsTool
{
    public static function listPlugins(array $args): array
    {
        $plugins = Craft::$app->getPlugins();
        $out = [];
        foreach ($plugins->getAllPluginInfo() as $handle => $info) {
            $out[] = [
                'handle' => $handle,
                'name' => $info['name'] ?? $handle,
                'installed' => (bool)($info['isInstalled'] ?? false),
                'enabled' => (bool)($info['isEnabled'] ?? false),
                'version' => $info['version'] ?? null,
            ];
        }
        return ['plugins' => $out];
    }

    public static function installPlugin(array $args): array
    {
        Support::requireAdmin();
        $handle = self::handle($args);
        Craft::$app->getPlugins()->installPlugin($handle);
        return ['handle' => $handle, 'installed' => true];
    }

    public static function uninstallPlugin(array $args): array
    {
        Support::requireAdmin();
        $handle = self::handle($args);
        Craft::$app->getPlugins()->uninstallPlugin($handle);
        return ['handle' => $handle, 'uninstalled' => true];
    }

    public static function enablePlugin(array $args): array
    {
        Support::requireAdmin();
        $handle = self::handle($args);
        Craft::$app->getPlugins()->enablePlugin($handle);
        return ['handle' => $handle, 'enabled' => true];
    }

    public static function disablePlugin(array $args): array
    {
        Support::requireAdmin();
        $handle = self::handle($args);
        Craft::$app->getPlugins()->disablePlugin($handle);
        return ['handle' => $handle, 'disabled' => true];
    }

    private static function handle(array $args): string
    {
        $handle = $args['handle'] ?? null;
        if (!$handle || !is_string($handle)) {
            throw new \RuntimeException('This tool requires a plugin "handle"');
        }
        return $handle;
    }
}

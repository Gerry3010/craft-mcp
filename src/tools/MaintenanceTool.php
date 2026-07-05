<?php

namespace gerry3010\mcp\tools;

use Craft;
use craft\helpers\FileHelper;

/**
 * Maintenance tools — cache clearing, queue status, and garbage collection.
 */
class MaintenanceTool
{
    public static function clearCaches(array $args): array
    {
        $which = $args['which'] ?? ['data', 'compiled-templates', 'element-caches'];
        if (!is_array($which)) {
            $which = [$which];
        }
        $done = [];

        if (in_array('data', $which, true)) {
            Craft::$app->getCache()->flush();
            $done[] = 'data';
        }
        if (in_array('compiled-templates', $which, true)) {
            try {
                FileHelper::clearDirectory(Craft::$app->getPath()->getCompiledTemplatesPath());
            } catch (\Throwable $e) {
                // Directory may not exist yet — treat as already clear.
            }
            $done[] = 'compiled-templates';
        }
        if (in_array('element-caches', $which, true)) {
            Craft::$app->getElements()->invalidateAllCaches();
            $done[] = 'element-caches';
        }

        return ['cleared' => $done];
    }

    public static function queueStatus(array $args): array
    {
        $queue = Craft::$app->getQueue();
        $total = method_exists($queue, 'getTotalJobs') ? $queue->getTotalJobs() : null;

        $jobs = [];
        if (method_exists($queue, 'getJobInfo')) {
            foreach ($queue->getJobInfo() as $info) {
                $jobs[] = [
                    'id' => $info['id'] ?? null,
                    'status' => $info['status'] ?? null,
                    'progress' => $info['progress'] ?? null,
                    'description' => $info['description'] ?? null,
                    'error' => $info['error'] ?? null,
                ];
            }
        }

        return ['totalJobs' => $total, 'jobs' => $jobs];
    }

    public static function runGc(array $args): array
    {
        Support::requireAdmin();
        Craft::$app->getGc()->run(true);
        return ['ran' => true];
    }
}

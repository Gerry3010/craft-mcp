<?php

namespace gerry3010\mcp\tools;

use Craft;
use craft\elements\Asset;

/**
 * Asset tools — list, upload (from a local path or a base64 payload) and delete.
 * Write ops are checked against saveAssets/deleteAssets:<volumeUid>.
 */
class AssetsTool
{
    public static function listAssets(array $args): array
    {
        $query = Asset::find();
        if (!empty($args['volume'])) {
            $query->volume($args['volume']);
        }
        if (!empty($args['folderId'])) {
            $query->folderId((int)$args['folderId']);
        }
        if (!empty($args['search'])) {
            $query->search($args['search']);
        }
        if (!empty($args['kind'])) {
            $query->kind($args['kind']);
        }
        $query->limit((int)($args['limit'] ?? 50));
        $query->offset((int)($args['offset'] ?? 0));

        $assets = array_map(static function (Asset $a) {
            return [
                'id' => $a->id,
                'filename' => $a->filename,
                'title' => $a->title,
                'kind' => $a->kind,
                'folderId' => $a->folderId,
                'url' => (function () use ($a) {
                    try {
                        return $a->getUrl();
                    } catch (\Throwable) {
                        return null;
                    }
                })(),
                'size' => $a->size,
            ];
        }, $query->all());

        return ['count' => count($assets), 'assets' => $assets];
    }

    public static function listAssetFolders(array $args): array
    {
        $out = [];
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $root = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
            $out[] = [
                'volume' => $volume->handle,
                'name' => $volume->name,
                'rootFolderId' => $root?->id,
            ];
        }
        return $out;
    }

    public static function uploadAsset(array $args): array
    {
        $folderId = (int)($args['folderId'] ?? 0);
        if (!$folderId) {
            // Default to the first volume's root folder.
            $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
            if (!$volume) {
                throw new \RuntimeException('No asset volume exists to upload into');
            }
            $folderId = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)?->id ?? 0;
        }
        $folder = Craft::$app->getAssets()->getFolderById($folderId);
        if (!$folder) {
            throw new \RuntimeException("Asset folder {$folderId} not found");
        }
        Support::requirePermission("saveAssets:{$folder->getVolume()->uid}");

        // Two input modes: a local file path, or an inline base64 payload.
        $tempFilePath = $args['tempFilePath'] ?? $args['filePath'] ?? null;
        $cleanup = false;
        if (!$tempFilePath && !empty($args['contentBase64'])) {
            $data = base64_decode($args['contentBase64'], true);
            if ($data === false) {
                throw new \RuntimeException('contentBase64 is not valid base64');
            }
            $tempFilePath = Craft::$app->getPath()->getTempPath() . '/mcp-' . uniqid('', true);
            file_put_contents($tempFilePath, $data);
            $cleanup = true;
        }
        if (!$tempFilePath) {
            throw new \RuntimeException('upload_asset requires "tempFilePath" or "contentBase64"');
        }
        if (!is_file($tempFilePath)) {
            throw new \RuntimeException("File not found: {$tempFilePath}");
        }

        try {
            $asset = new Asset();
            $asset->tempFilePath = $tempFilePath;
            // Craft 5 derives the required `newLocation` from newFolderId + filename;
            // setting only `folderId` leaves newLocation blank and the save fails.
            $asset->newFolderId = $folderId;
            $asset->setFilename($args['filename'] ?? basename((string)($args['tempFilePath'] ?? $args['filePath'] ?? 'upload.bin')));
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            Support::save($asset, 'asset');
        } finally {
            if ($cleanup && is_file($tempFilePath)) {
                @unlink($tempFilePath);
            }
        }

        return [
            'id' => $asset->id,
            'filename' => $asset->filename,
            'url' => (function () use ($asset) {
                try {
                    return $asset->getUrl();
                } catch (\Throwable) {
                    return null;
                }
            })(),
        ];
    }

    public static function deleteAsset(array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $asset = Asset::find()->id($id)->one();
        if (!$asset) {
            throw new \RuntimeException("Asset {$id} not found");
        }
        Support::requirePermission("deleteAssets:{$asset->getVolume()->uid}");
        if (!Craft::$app->getElements()->deleteElement($asset)) {
            throw new ValidationException("Failed to delete asset {$id}", $asset->getErrors());
        }
        return ['id' => $id, 'deleted' => true];
    }
}

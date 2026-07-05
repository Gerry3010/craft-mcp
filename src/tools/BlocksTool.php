<?php

namespace gerry3010\mcp\tools;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\Matrix as MatrixField;

/**
 * Manage Matrix blocks (which are nested entries in Craft 5).
 *
 * Owner is generic: `ownerId` is any element (an entry, a global set, or a
 * parent Matrix block for nested Matrix) and `field` is the Matrix field
 * handle. The same five operations therefore work for every Matrix field in the
 * install — no per-field special-casing.
 *
 * Writes use Craft's serialized Matrix form on the owner:
 *   $owner->setFieldValue($field, ['sortOrder' => [ids...], 'entries' => [id => {type, fields}]]);
 * The key order in sortOrder is the block order; existing IDs omitted from
 * sortOrder are deleted; a 'newX' key inserts a new block.
 */
class BlocksTool
{
    public static function listBlocks(array $args): array
    {
        [$owner, $field] = self::resolve($args);
        $blocks = self::currentBlocks($owner, $field);
        return [
            'ownerId' => $owner->id,
            'field' => $field,
            'blocks' => array_map(
                static fn(Entry $b) => Support::serialize($b, true),
                $blocks
            ),
        ];
    }

    public static function addBlock(array $args): array
    {
        [$owner, $field] = self::resolve($args);
        $type = $args['type'] ?? null;
        if (!$type) {
            throw new \RuntimeException('add_block requires "type" (block entry type handle)');
        }
        self::assertType($owner, $field, $type);

        $existing = self::currentBlocks($owner, $field);
        $ids = array_map(static fn(Entry $b) => $b->id, $existing);

        // Insert position: 0-based; omitted/out-of-range => append.
        $position = $args['position'] ?? null;
        $sortOrder = $ids;
        $insertAt = ($position === null) ? count($ids) : max(0, min((int)$position, count($ids)));
        array_splice($sortOrder, $insertAt, 0, ['new1']);

        $entries = [];
        foreach ($ids as $id) {
            $entries[$id] = []; // keep existing block as-is
        }
        $entries['new1'] = [
            'type' => $type,
            'enabled' => $args['enabled'] ?? true,
            'fields' => $args['fields'] ?? [],
        ];

        $owner->setFieldValue($field, ['sortOrder' => $sortOrder, 'entries' => $entries]);
        Support::save($owner, 'owner element');

        // Find the newly created block by diffing IDs.
        $after = self::currentBlocks($owner, $field);
        $newBlock = null;
        $beforeSet = array_flip($ids);
        foreach ($after as $b) {
            if (!isset($beforeSet[$b->id])) {
                $newBlock = $b;
                break;
            }
        }

        return [
            'ownerId' => $owner->id,
            'field' => $field,
            'blockId' => $newBlock?->id,
            'block' => $newBlock ? Support::serialize($newBlock, true) : null,
        ];
    }

    public static function updateBlock(array $args): array
    {
        $blockId = (int)($args['blockId'] ?? 0);
        $block = Entry::find()->id($blockId)->status(null)->one();
        if (!$block) {
            throw new \RuntimeException("Block {$blockId} not found");
        }
        $fields = $args['fields'] ?? [];
        if (!is_array($fields)) {
            throw new \RuntimeException('update_block "fields" must be an object');
        }
        foreach ($fields as $handle => $value) {
            $block->setFieldValue($handle, $value);
        }
        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $block->enabled = (bool)$args['enabled'];
        }
        Support::save($block, 'block');
        return Support::serialize($block, true);
    }

    public static function moveBlock(array $args): array
    {
        [$owner, $field] = self::resolve($args);
        $blockId = (int)($args['blockId'] ?? 0);
        $position = (int)($args['position'] ?? 0);

        $ids = array_map(static fn(Entry $b) => $b->id, self::currentBlocks($owner, $field));
        if (!in_array($blockId, $ids, true)) {
            throw new \RuntimeException("Block {$blockId} is not in {$field} of owner {$owner->id}");
        }
        $ids = array_values(array_filter($ids, static fn($id) => $id !== $blockId));
        $insertAt = max(0, min($position, count($ids)));
        array_splice($ids, $insertAt, 0, [$blockId]);

        self::saveOrder($owner, $field, $ids);
        return self::listBlocks($args);
    }

    public static function deleteBlock(array $args): array
    {
        [$owner, $field] = self::resolve($args);
        $blockId = (int)($args['blockId'] ?? 0);

        $ids = array_map(static fn(Entry $b) => $b->id, self::currentBlocks($owner, $field));
        if (!in_array($blockId, $ids, true)) {
            throw new \RuntimeException("Block {$blockId} is not in {$field} of owner {$owner->id}");
        }
        $kept = array_values(array_filter($ids, static fn($id) => $id !== $blockId));

        self::saveOrder($owner, $field, $kept);
        return ['ownerId' => $owner->id, 'field' => $field, 'deletedBlockId' => $blockId, 'remaining' => count($kept)];
    }

    // -- internals ----------------------------------------------------------

    /**
     * @return array{0: ElementInterface, 1: string}
     */
    private static function resolve(array $args): array
    {
        $ownerId = (int)($args['ownerId'] ?? 0);
        $field = $args['field'] ?? null;
        if (!$field) {
            throw new \RuntimeException('block tools require "field" (the Matrix field handle)');
        }
        $owner = Support::element($ownerId);
        if (!$owner) {
            throw new \RuntimeException("Owner element {$ownerId} not found");
        }
        if (!self::matrixField($owner, $field)) {
            throw new \RuntimeException("Owner {$ownerId} has no Matrix field '{$field}'");
        }
        return [$owner, $field];
    }

    private static function matrixField(ElementInterface $owner, string $handle): ?MatrixField
    {
        $layout = $owner->getFieldLayout();
        if (!$layout) {
            return null;
        }
        $field = $layout->getFieldByHandle($handle);
        return $field instanceof MatrixField ? $field : null;
    }

    private static function assertType(ElementInterface $owner, string $field, string $type): void
    {
        $matrix = self::matrixField($owner, $field);
        $allowed = array_map(static fn($et) => $et->handle, $matrix->getEntryTypes());
        if (!in_array($type, $allowed, true)) {
            throw new \RuntimeException(
                "Block type '{$type}' not allowed in '{$field}'. Allowed: " . implode(', ', $allowed)
            );
        }
    }

    /**
     * @return Entry[] ordered nested block entries
     */
    private static function currentBlocks(ElementInterface $owner, string $field): array
    {
        $value = $owner->getFieldValue($field);
        if ($value instanceof ElementQueryInterface) {
            return $value->status(null)->all();
        }
        return is_array($value) ? $value : [];
    }

    /**
     * Persist a pure reordering (or deletion) — no field edits on the blocks.
     *
     * @param int[] $orderedIds
     */
    private static function saveOrder(ElementInterface $owner, string $field, array $orderedIds): void
    {
        $entries = [];
        foreach ($orderedIds as $id) {
            $entries[$id] = []; // keep as-is
        }
        $owner->setFieldValue($field, ['sortOrder' => $orderedIds, 'entries' => $entries]);
        Support::save($owner, 'owner element');
    }
}

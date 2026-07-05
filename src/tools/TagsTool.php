<?php

namespace gerry3010\mcp\tools;

use Craft;
use craft\elements\Tag;

/**
 * Tag CRUD. Tags are simple elements grouped by tag group.
 */
class TagsTool
{
    public static function listTags(array $args): array
    {
        $group = $args['group'] ?? null;
        if (!$group) {
            throw new \RuntimeException('list_tags requires "group" (tag group handle)');
        }
        $query = Tag::find()->group($group)->status(null);
        if (!empty($args['search'])) {
            $query->search($args['search']);
        }
        $query->limit((int)($args['limit'] ?? 100));
        $query->offset((int)($args['offset'] ?? 0));

        $items = array_map(static fn(Tag $t) => Support::serialize($t, false), $query->all());
        return ['group' => $group, 'count' => count($items), 'tags' => $items];
    }

    public static function createTag(array $args): array
    {
        $groupHandle = $args['group'] ?? null;
        if (!$groupHandle) {
            throw new \RuntimeException('create_tag requires "group"');
        }
        $group = Craft::$app->getTags()->getTagGroupByHandle($groupHandle);
        if (!$group) {
            throw new \RuntimeException("Tag group not found: {$groupHandle}");
        }
        // Tag editing is not a granular Craft permission; require CP access.
        Support::requirePermission('accessCp');

        $tag = new Tag();
        $tag->groupId = $group->id;
        if (array_key_exists('title', $args) && $args['title'] !== null) {
            $tag->title = $args['title'];
        }
        if (!empty($args['fields']) && is_array($args['fields'])) {
            foreach ($args['fields'] as $handle => $value) {
                $tag->setFieldValue($handle, $value);
            }
        }
        Support::save($tag, 'tag');
        return Support::serialize($tag, false);
    }

    public static function deleteTag(array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $tag = Tag::find()->id($id)->status(null)->one();
        if (!$tag) {
            throw new \RuntimeException("Tag {$id} not found");
        }
        Support::requirePermission('accessCp');
        if (!Craft::$app->getElements()->deleteElement($tag)) {
            throw new ValidationException("Failed to delete tag {$id}", $tag->getErrors());
        }
        return ['id' => $id, 'deleted' => true];
    }
}

<?php

namespace gerry3010\mcp\tools;

use Craft;
use craft\elements\Category;

/**
 * Category CRUD. Categories are structured elements grouped by category group.
 */
class CategoriesTool
{
    public static function listCategories(array $args): array
    {
        $group = $args['group'] ?? null;
        if (!$group) {
            throw new \RuntimeException('list_categories requires "group" (category group handle)');
        }
        $query = Category::find()->group($group)->status(null);
        if (!empty($args['search'])) {
            $query->search($args['search']);
        }
        $query->limit((int)($args['limit'] ?? 100));
        $query->offset((int)($args['offset'] ?? 0));

        $items = array_map(static fn(Category $c) => Support::serialize($c, false), $query->all());
        return ['group' => $group, 'count' => count($items), 'categories' => $items];
    }

    public static function getCategory(array $args): array
    {
        $cat = self::find((int)($args['id'] ?? 0));
        return Support::serialize($cat, true);
    }

    public static function createCategory(array $args): array
    {
        $groupHandle = $args['group'] ?? null;
        if (!$groupHandle) {
            throw new \RuntimeException('create_category requires "group"');
        }
        $group = Craft::$app->getCategories()->getGroupByHandle($groupHandle);
        if (!$group) {
            throw new \RuntimeException("Category group not found: {$groupHandle}");
        }
        Support::requirePermission("saveCategories:{$group->uid}");

        $cat = new Category();
        $cat->groupId = $group->id;
        self::applyProps($cat, $args);
        Support::save($cat, 'category');
        return Support::serialize($cat, false);
    }

    public static function updateCategory(array $args): array
    {
        $cat = self::find((int)($args['id'] ?? 0));
        Support::requirePermission("saveCategories:{$cat->getGroup()->uid}");
        self::applyProps($cat, $args);
        Support::save($cat, 'category');
        return Support::serialize($cat, false);
    }

    public static function deleteCategory(array $args): array
    {
        $cat = self::find((int)($args['id'] ?? 0));
        Support::requirePermission("saveCategories:{$cat->getGroup()->uid}");
        if (!Craft::$app->getElements()->deleteElement($cat)) {
            throw new ValidationException("Failed to delete category {$cat->id}", $cat->getErrors());
        }
        return ['id' => $cat->id, 'deleted' => true];
    }

    private static function find(int $id): Category
    {
        $cat = Category::find()->id($id)->status(null)->one();
        if (!$cat) {
            throw new \RuntimeException("Category {$id} not found");
        }
        return $cat;
    }

    private static function applyProps(Category $cat, array $args): void
    {
        if (array_key_exists('title', $args) && $args['title'] !== null) {
            $cat->title = $args['title'];
        }
        if (array_key_exists('slug', $args) && $args['slug'] !== null) {
            $cat->slug = $args['slug'];
        }
        if (array_key_exists('enabled', $args) && $args['enabled'] !== null) {
            $cat->enabled = (bool)$args['enabled'];
        }
        if (!empty($args['parentId'])) {
            $cat->setParentId((int)$args['parentId']);
        }
        if (!empty($args['fields']) && is_array($args['fields'])) {
            foreach ($args['fields'] as $handle => $value) {
                $cat->setFieldValue($handle, $value);
            }
        }
    }
}

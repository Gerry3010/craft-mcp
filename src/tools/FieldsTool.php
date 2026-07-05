<?php

namespace gerry3010\mcp\tools;

use Craft;

/**
 * Field management (schema-write). list/get are read; save/delete are gated
 * structural operations requiring an admin Craft user + confirm.
 */
class FieldsTool
{
    public static function listFields(array $args): array
    {
        return array_map(static fn($f) => [
            'id' => $f->id,
            'uid' => $f->uid,
            'handle' => $f->handle,
            'name' => $f->name,
            'type' => Support::shortClass($f),
            'typeClass' => get_class($f),
        ], Craft::$app->getFields()->getAllFields());
    }

    public static function getField(array $args): array
    {
        $field = self::resolve($args);
        return [
            'id' => $field->id,
            'uid' => $field->uid,
            'handle' => $field->handle,
            'name' => $field->name,
            'instructions' => $field->instructions,
            'type' => get_class($field),
            'searchable' => $field->searchable,
            'settings' => $field->getSettings(),
        ];
    }

    /**
     * Create or update a field from a config array:
     *   { type, handle, name, instructions?, searchable?, settings?, id? }
     * `type` is the field class (FQCN, e.g. "craft\\fields\\PlainText").
     */
    public static function saveField(array $args): array
    {
        Support::requireAdmin();
        $config = $args['config'] ?? $args;

        if (!empty($config['id']) || !empty($config['handle'])) {
            // Prefer updating an existing field when id/handle resolves.
            $existing = !empty($config['id'])
                ? Craft::$app->getFields()->getFieldById((int)$config['id'])
                : Craft::$app->getFields()->getFieldByHandle($config['handle']);
        } else {
            $existing = null;
        }

        if (empty($config['type']) && !$existing) {
            throw new \RuntimeException('save_field requires "type" (field class) for new fields');
        }

        $fieldConfig = [
            'type' => $config['type'] ?? get_class($existing),
            'id' => $existing?->id,
            'uid' => $existing?->uid,
            'name' => $config['name'] ?? $existing?->name,
            'handle' => $config['handle'] ?? $existing?->handle,
            'instructions' => $config['instructions'] ?? $existing?->instructions,
            'searchable' => $config['searchable'] ?? $existing?->searchable ?? false,
            'settings' => $config['settings'] ?? ($existing ? $existing->getSettings() : []),
        ];

        $field = Craft::$app->getFields()->createField($fieldConfig);
        if (!Craft::$app->getFields()->saveField($field)) {
            throw new ValidationException('Failed to save field', $field->getErrors());
        }

        return ['id' => $field->id, 'handle' => $field->handle, 'uid' => $field->uid, 'saved' => true];
    }

    public static function deleteField(array $args): array
    {
        Support::requireAdmin();
        $field = self::resolve($args);
        if (!Craft::$app->getFields()->deleteField($field)) {
            throw new \RuntimeException("Failed to delete field '{$field->handle}'");
        }
        return ['handle' => $field->handle, 'deleted' => true];
    }

    private static function resolve(array $args)
    {
        $field = null;
        if (!empty($args['id'])) {
            $field = Craft::$app->getFields()->getFieldById((int)$args['id']);
        } elseif (!empty($args['handle'])) {
            $field = Craft::$app->getFields()->getFieldByHandle($args['handle']);
        }
        if (!$field) {
            throw new \RuntimeException('Field not found (provide "id" or "handle")');
        }
        return $field;
    }
}

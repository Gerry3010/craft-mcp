<?php

namespace gerry3010\mcp\tools;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Assets as AssetsField;
use craft\fields\BaseOptionsField;
use craft\fields\BaseRelationField;
use craft\fields\Matrix as MatrixField;
use craft\models\FieldLayout;
use DateTimeInterface;
use ReflectionClass;

/**
 * Shared helpers for the MCP tools: element loading, element/field
 * serialization, and field-layout introspection.
 *
 * Ported from the Homepage `modules/mcp` prototype; generic across any Craft 5
 * content model (no hardcoded handles).
 */
class Support
{
    /**
     * Load any element by ID, ignoring status/draft/revision restrictions so
     * disabled entries and nested Matrix blocks resolve too.
     */
    public static function element(int $id): ?ElementInterface
    {
        return Craft::$app->getElements()->getElementById($id, null, null, [
            'status' => null,
            'drafts' => null,
            'revisions' => null,
        ]);
    }

    /**
     * Save an element or throw a ValidationException carrying its errors.
     */
    public static function save(ElementInterface $element, string $context = 'element'): void
    {
        if (!Craft::$app->getElements()->saveElement($element)) {
            throw new ValidationException(
                "Failed to save {$context}",
                $element->getErrors()
            );
        }
    }

    public static function shortClass(object $obj): string
    {
        return (new ReflectionClass($obj))->getShortName();
    }

    /**
     * Enforce a Craft permission against the token's Craft user. Admins bypass
     * automatically (Craft's checkPermission returns true for admins). This is
     * how "full control" stays bounded by Craft's own ACL.
     */
    public static function requirePermission(string $permission): void
    {
        if (!Craft::$app->getUser()->checkPermission($permission)) {
            throw new \RuntimeException(
                "Permission denied: the authenticated Craft user lacks '{$permission}'."
            );
        }
    }

    /**
     * Require that the token's Craft user is an admin — used for structural and
     * system-level operations.
     */
    public static function requireAdmin(): void
    {
        $identity = Craft::$app->getUser()->getIdentity();
        if (!$identity || !$identity->admin) {
            throw new \RuntimeException(
                'Permission denied: this operation requires an admin Craft user.'
            );
        }
    }

    // -- Serialization ------------------------------------------------------

    /**
     * Serialize an element to a plain array. When $deep is true, Matrix fields
     * are expanded into their nested block tree (bounded by $depth).
     */
    public static function serialize(ElementInterface $element, bool $deep = false, int $depth = 0): array
    {
        $out = ['id' => $element->id];

        if ($element instanceof Entry) {
            $out['type'] = $element->getType()->handle;
            $out['title'] = $element->title;
            $out['slug'] = $element->slug;
            $out['status'] = $element->getStatus();
            $out['enabled'] = (bool)$element->enabled;
            try {
                $out['url'] = $element->getUrl();
            } catch (\Throwable) {
                $out['url'] = null;
            }
            $out['postDate'] = $element->postDate?->format(DateTimeInterface::ATOM);
            $out['dateUpdated'] = $element->dateUpdated?->format(DateTimeInterface::ATOM);
        } else {
            $out['type'] = self::shortClass($element);
            if (property_exists($element, 'title')) {
                $out['title'] = $element->title ?? null;
            }
        }

        $layout = $element->getFieldLayout();
        if ($layout !== null) {
            $fields = [];
            foreach ($layout->getCustomFields() as $field) {
                $fields[$field->handle] = self::serializeFieldValue($element, $field, $deep, $depth);
            }
            $out['fields'] = $fields;
        }

        return $out;
    }

    private static function serializeFieldValue(ElementInterface $element, FieldInterface $field, bool $deep, int $depth): mixed
    {
        try {
            $value = $element->getFieldValue($field->handle);
        } catch (\Throwable $e) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        // Matrix (content builder, nested blocks) -> ordered blocks
        if ($field instanceof MatrixField) {
            $blocks = $value instanceof ElementQueryInterface ? $value->status(null)->all() : (is_iterable($value) ? $value : []);
            $result = [];
            foreach ($blocks as $block) {
                if ($deep && $depth < 3) {
                    $result[] = self::serialize($block, true, $depth + 1);
                } else {
                    $result[] = [
                        'id' => $block->id,
                        'type' => $block instanceof Entry ? $block->getType()->handle : self::shortClass($block),
                    ];
                }
            }
            return $result;
        }

        // Assets -> {id, filename, url, kind}
        if ($field instanceof AssetsField) {
            $assets = $value instanceof ElementQueryInterface ? $value->all() : (is_iterable($value) ? $value : []);
            return array_map(static function(Asset $a) {
                return [
                    'id' => $a->id,
                    'filename' => $a->filename,
                    'url' => (function() use ($a) {
                        try {
                            return $a->getUrl();
                        } catch (\Throwable) {
                            return null;
                        }
                    })(),
                    'kind' => $a->kind,
                ];
            }, $assets);
        }

        // Other relations (entries, categories, ...) -> {id, title}
        if ($field instanceof BaseRelationField) {
            $related = $value instanceof ElementQueryInterface ? $value->all() : (is_iterable($value) ? $value : []);
            return array_map(static fn(ElementInterface $e) => [
                'id' => $e->id,
                'title' => $e->title ?? (string)$e,
            ], $related);
        }

        // Dropdowns / radio / single option -> value string
        if ($field instanceof BaseOptionsField) {
            if (is_object($value) && property_exists($value, 'value')) {
                return $value->value;
            }
            return is_scalar($value) ? $value : (string)$value;
        }

        // Dates -> ISO 8601
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_scalar($value)) {
            return $value;
        }

        // Link fields, CKEditor, and anything else stringable
        try {
            return (string)$value;
        } catch (\Throwable) {
            return null;
        }
    }

    // -- Field-layout introspection (describe_content_model) ----------------

    /**
     * Describe the custom fields of a field layout. Matrix fields recurse into
     * their allowed block types so the full content-builder palette is visible.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function describeLayout(?FieldLayout $layout, int $depth = 0): array
    {
        if ($layout === null) {
            return [];
        }

        // Collect required flags from the layout elements.
        $required = [];
        foreach ($layout->getTabs() as $tab) {
            foreach ($tab->getElements() as $el) {
                if ($el instanceof CustomField) {
                    $f = $el->getField();
                    $required[$f->handle] = (bool)$el->required;
                }
            }
        }

        $fields = [];
        foreach ($layout->getCustomFields() as $field) {
            $fields[] = self::describeField($field, $required[$field->handle] ?? false, $depth);
        }
        return $fields;
    }

    private static function describeField(FieldInterface $field, bool $required, int $depth): array
    {
        $info = [
            'handle' => $field->handle,
            'name' => $field->name,
            'type' => self::shortClass($field),
            'required' => $required,
        ];

        if ($field instanceof MatrixField) {
            $blockTypes = [];
            foreach ($field->getEntryTypes() as $bt) {
                $blockTypes[] = [
                    'handle' => $bt->handle,
                    'name' => $bt->name,
                    'hasTitleField' => $bt->hasTitleField,
                    'fields' => $depth < 2 ? self::describeLayout($bt->getFieldLayout(), $depth + 1) : [],
                ];
            }
            $info['blockTypes'] = $blockTypes;
        }

        if ($field instanceof BaseOptionsField) {
            $info['options'] = array_map(static fn($o) => [
                'label' => $o['label'] ?? null,
                'value' => $o['value'] ?? null,
            ], $field->options);
        }

        if ($field instanceof AssetsField) {
            $info['maxRelations'] = $field->maxRelations;
            $info['accepts'] = 'array of asset IDs';
        }

        if ($field instanceof BaseRelationField && !($field instanceof AssetsField)) {
            $info['accepts'] = 'array of element IDs';
        }

        if (property_exists($field, 'types') && self::shortClass($field) === 'Link') {
            // craft\fields\Link — allowed link types (url, entry, email, tel, ...)
            $info['linkTypes'] = $field->types;
            $info['accepts'] = 'URL/email/tel string';
        }

        if (str_contains(get_class($field), 'ckeditor')) {
            $info['format'] = 'HTML';
        }

        return $info;
    }
}

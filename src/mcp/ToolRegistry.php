<?php

namespace gerry3010\mcp\mcp;

use gerry3010\mcp\tools\AssetsTool;
use gerry3010\mcp\tools\BlocksTool;
use gerry3010\mcp\tools\CategoriesTool;
use gerry3010\mcp\tools\EntriesTool;
use gerry3010\mcp\tools\FieldsTool;
use gerry3010\mcp\tools\GlobalsTool;
use gerry3010\mcp\tools\GraphqlTool;
use gerry3010\mcp\tools\MaintenanceTool;
use gerry3010\mcp\tools\PluginsTool;
use gerry3010\mcp\tools\ProjectConfigTool;
use gerry3010\mcp\tools\SchemaTool;
use gerry3010\mcp\tools\StructureTool;
use gerry3010\mcp\tools\TagsTool;
use gerry3010\mcp\tools\UsersTool;

/**
 * The single source of truth for the MCP tool surface.
 *
 * Each entry: [description, risk, handler, inputSchema].
 *  - risk: 'read' | 'content-write' | 'schema-write' | 'system-write'
 *  - handler: [class, staticMethod] receiving array $args, returning mixed
 *  - inputSchema: JSON Schema (as a PHP array) for the tool arguments
 *
 * Gating by risk tier is enforced in Server::callTool().
 */
class ToolRegistry
{
    public const RISK_READ = 'read';
    public const RISK_CONTENT_WRITE = 'content-write';
    public const RISK_SCHEMA_WRITE = 'schema-write';
    public const RISK_SYSTEM_WRITE = 'system-write';

    /**
     * @return array<string, array{description:string, risk:string, handler:array, inputSchema:array}>
     */
    public static function all(): array
    {
        $obj = static fn(array $props = [], array $required = []): array => [
            'type' => 'object',
            'properties' => (object)$props,
            'required' => $required,
            'additionalProperties' => true,
        ];
        $str = ['type' => 'string'];
        $int = ['type' => 'integer'];
        $bool = ['type' => 'boolean'];
        $fields = ['type' => 'object', 'description' => 'Map of fieldHandle => value'];

        return [
            // ---- Introspection ------------------------------------------------
            'describe_content_model' => [
                'description' => 'Dump the content model (sections, entry types, fields, block types, globals, groups, volumes, sites). Call this first — the client needs no hardcoded handles. Optionally scope with section/entryType.',
                'risk' => self::RISK_READ,
                'handler' => [SchemaTool::class, 'describe'],
                'inputSchema' => $obj(['section' => $str, 'entryType' => $str]),
            ],
            'list_sections' => [
                'description' => 'List all sections and their entry types.',
                'risk' => self::RISK_READ,
                'handler' => [EntriesTool::class, 'listSections'],
                'inputSchema' => $obj(),
            ],

            // ---- Entries ------------------------------------------------------
            'list_entries' => [
                'description' => 'List entries in a section (newest first). Args: section (required), entryType, search, status (all|live|disabled|pending|expired), limit, offset.',
                'risk' => self::RISK_READ,
                'handler' => [EntriesTool::class, 'listEntries'],
                'inputSchema' => $obj(['section' => $str, 'entryType' => $str, 'search' => $str, 'status' => $str, 'limit' => $int, 'offset' => $int], ['section']),
            ],
            'get_entry' => [
                'description' => 'Get one entry by id, including its full block tree (includeBlocks defaults true).',
                'risk' => self::RISK_READ,
                'handler' => [EntriesTool::class, 'getEntry'],
                'inputSchema' => $obj(['id' => $int, 'includeBlocks' => $bool], ['id']),
            ],
            'create_entry' => [
                'description' => 'Create an entry (saved directly live). Args: section (required), entryType, title, slug, enabled, authorId, fields.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [EntriesTool::class, 'createEntry'],
                'inputSchema' => $obj(['section' => $str, 'entryType' => $str, 'title' => $str, 'slug' => $str, 'enabled' => $bool, 'authorId' => $int, 'fields' => $fields], ['section']),
            ],
            'update_entry' => [
                'description' => 'Partially update an entry by id. Args: id (required), title, slug, enabled, authorId, fields.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [EntriesTool::class, 'updateEntry'],
                'inputSchema' => $obj(['id' => $int, 'title' => $str, 'slug' => $str, 'enabled' => $bool, 'authorId' => $int, 'fields' => $fields], ['id']),
            ],
            'delete_entry' => [
                'description' => 'Delete (trash) an entry by id.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [EntriesTool::class, 'deleteEntry'],
                'inputSchema' => $obj(['id' => $int], ['id']),
            ],

            // ---- Matrix blocks (nested entries) -------------------------------
            'list_blocks' => [
                'description' => 'List Matrix blocks of an owner. Args: ownerId (required), field (required Matrix handle).',
                'risk' => self::RISK_READ,
                'handler' => [BlocksTool::class, 'listBlocks'],
                'inputSchema' => $obj(['ownerId' => $int, 'field' => $str], ['ownerId', 'field']),
            ],
            'add_block' => [
                'description' => 'Add a Matrix block. Args: ownerId, field, type (block entry-type handle), fields, position (0-based), enabled.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [BlocksTool::class, 'addBlock'],
                'inputSchema' => $obj(['ownerId' => $int, 'field' => $str, 'type' => $str, 'fields' => $fields, 'position' => $int, 'enabled' => $bool], ['ownerId', 'field', 'type']),
            ],
            'update_block' => [
                'description' => 'Update a Matrix block by blockId. Args: blockId, fields, enabled.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [BlocksTool::class, 'updateBlock'],
                'inputSchema' => $obj(['blockId' => $int, 'fields' => $fields, 'enabled' => $bool], ['blockId']),
            ],
            'move_block' => [
                'description' => 'Reorder a Matrix block to a 0-based position. Args: ownerId, field, blockId, position.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [BlocksTool::class, 'moveBlock'],
                'inputSchema' => $obj(['ownerId' => $int, 'field' => $str, 'blockId' => $int, 'position' => $int], ['ownerId', 'field', 'blockId', 'position']),
            ],
            'delete_block' => [
                'description' => 'Delete a Matrix block. Args: ownerId, field, blockId.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [BlocksTool::class, 'deleteBlock'],
                'inputSchema' => $obj(['ownerId' => $int, 'field' => $str, 'blockId' => $int], ['ownerId', 'field', 'blockId']),
            ],

            // ---- Categories & Tags -------------------------------------------
            'list_categories' => [
                'description' => 'List categories in a group. Args: group (required handle), search, limit, offset.',
                'risk' => self::RISK_READ,
                'handler' => [CategoriesTool::class, 'listCategories'],
                'inputSchema' => $obj(['group' => $str, 'search' => $str, 'limit' => $int, 'offset' => $int], ['group']),
            ],
            'get_category' => [
                'description' => 'Get one category by id (with fields/blocks).',
                'risk' => self::RISK_READ,
                'handler' => [CategoriesTool::class, 'getCategory'],
                'inputSchema' => $obj(['id' => $int], ['id']),
            ],
            'create_category' => [
                'description' => 'Create a category. Args: group (required), title, slug, enabled, parentId, fields.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [CategoriesTool::class, 'createCategory'],
                'inputSchema' => $obj(['group' => $str, 'title' => $str, 'slug' => $str, 'enabled' => $bool, 'parentId' => $int, 'fields' => $fields], ['group']),
            ],
            'update_category' => [
                'description' => 'Update a category by id. Args: id, title, slug, enabled, parentId, fields.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [CategoriesTool::class, 'updateCategory'],
                'inputSchema' => $obj(['id' => $int, 'title' => $str, 'slug' => $str, 'enabled' => $bool, 'parentId' => $int, 'fields' => $fields], ['id']),
            ],
            'delete_category' => [
                'description' => 'Delete a category by id.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [CategoriesTool::class, 'deleteCategory'],
                'inputSchema' => $obj(['id' => $int], ['id']),
            ],
            'list_tags' => [
                'description' => 'List tags in a group. Args: group (required handle), search, limit, offset.',
                'risk' => self::RISK_READ,
                'handler' => [TagsTool::class, 'listTags'],
                'inputSchema' => $obj(['group' => $str, 'search' => $str, 'limit' => $int, 'offset' => $int], ['group']),
            ],
            'create_tag' => [
                'description' => 'Create a tag. Args: group (required), title, fields.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [TagsTool::class, 'createTag'],
                'inputSchema' => $obj(['group' => $str, 'title' => $str, 'fields' => $fields], ['group']),
            ],
            'delete_tag' => [
                'description' => 'Delete a tag by id.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [TagsTool::class, 'deleteTag'],
                'inputSchema' => $obj(['id' => $int], ['id']),
            ],

            // ---- Assets -------------------------------------------------------
            'list_assets' => [
                'description' => 'List assets. Args: volume, folderId, search, kind, limit, offset.',
                'risk' => self::RISK_READ,
                'handler' => [AssetsTool::class, 'listAssets'],
                'inputSchema' => $obj(['volume' => $str, 'folderId' => $int, 'search' => $str, 'kind' => $str, 'limit' => $int, 'offset' => $int]),
            ],
            'list_asset_folders' => [
                'description' => 'List volumes and their root folder ids.',
                'risk' => self::RISK_READ,
                'handler' => [AssetsTool::class, 'listAssetFolders'],
                'inputSchema' => $obj(),
            ],
            'upload_asset' => [
                'description' => 'Upload an asset from a local path (tempFilePath) or inline base64 (contentBase64 + filename). Args: folderId (defaults first volume root), filename.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [AssetsTool::class, 'uploadAsset'],
                'inputSchema' => $obj(['folderId' => $int, 'filename' => $str, 'tempFilePath' => $str, 'contentBase64' => $str]),
            ],
            'delete_asset' => [
                'description' => 'Delete an asset by id.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [AssetsTool::class, 'deleteAsset'],
                'inputSchema' => $obj(['id' => $int], ['id']),
            ],

            // ---- Globals ------------------------------------------------------
            'list_globals' => [
                'description' => 'List global sets with their ids (use the id as ownerId for global-set Matrix blocks).',
                'risk' => self::RISK_READ,
                'handler' => [GlobalsTool::class, 'listGlobals'],
                'inputSchema' => $obj(),
            ],
            'get_globals' => [
                'description' => 'Get a global set by handle (with fields/blocks).',
                'risk' => self::RISK_READ,
                'handler' => [GlobalsTool::class, 'getGlobals'],
                'inputSchema' => $obj(['handle' => $str], ['handle']),
            ],
            'update_globals' => [
                'description' => 'Update a global set. Args: handle (required), fields.',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [GlobalsTool::class, 'updateGlobals'],
                'inputSchema' => $obj(['handle' => $str, 'fields' => $fields], ['handle']),
            ],

            // ---- Fields (schema-write) ---------------------------------------
            'list_fields' => [
                'description' => 'List all custom fields (handle, name, type).',
                'risk' => self::RISK_READ,
                'handler' => [FieldsTool::class, 'listFields'],
                'inputSchema' => $obj(),
            ],
            'get_field' => [
                'description' => 'Get a field definition incl. settings. Args: id or handle.',
                'risk' => self::RISK_READ,
                'handler' => [FieldsTool::class, 'getField'],
                'inputSchema' => $obj(['id' => $int, 'handle' => $str]),
            ],
            'save_field' => [
                'description' => 'Create/update a field (admin, confirm). Args config: { type (field class FQCN), handle, name, instructions?, searchable?, settings?, id? }.',
                'risk' => self::RISK_SCHEMA_WRITE,
                'handler' => [FieldsTool::class, 'saveField'],
                'inputSchema' => $obj(['config' => ['type' => 'object'], 'confirm' => $bool]),
            ],
            'delete_field' => [
                'description' => 'Delete a field (admin, confirm). Args: id or handle.',
                'risk' => self::RISK_SCHEMA_WRITE,
                'handler' => [FieldsTool::class, 'deleteField'],
                'inputSchema' => $obj(['id' => $int, 'handle' => $str, 'confirm' => $bool]),
            ],

            // ---- Structure: sections & entry types (schema-write) ------------
            'save_entry_type' => [
                'description' => 'Create/update an entry type (admin, confirm). Args: handle, name, hasTitleField, titleFormat, fields (array of existing field handles).',
                'risk' => self::RISK_SCHEMA_WRITE,
                'handler' => [StructureTool::class, 'saveEntryType'],
                'inputSchema' => $obj(['handle' => $str, 'name' => $str, 'hasTitleField' => $bool, 'titleFormat' => $str, 'fields' => ['type' => 'array'], 'confirm' => $bool], ['handle']),
            ],
            'delete_entry_type' => [
                'description' => 'Delete an entry type by handle (admin, confirm).',
                'risk' => self::RISK_SCHEMA_WRITE,
                'handler' => [StructureTool::class, 'deleteEntryType'],
                'inputSchema' => $obj(['handle' => $str, 'confirm' => $bool], ['handle']),
            ],
            'save_section' => [
                'description' => 'Create/update a section (admin, confirm). Args: handle, name, type (single|channel|structure), entryTypes (handles), hasUrls, uriFormat, template, enableVersioning.',
                'risk' => self::RISK_SCHEMA_WRITE,
                'handler' => [StructureTool::class, 'saveSection'],
                'inputSchema' => $obj(['handle' => $str, 'name' => $str, 'type' => $str, 'entryTypes' => ['type' => 'array'], 'hasUrls' => $bool, 'uriFormat' => $str, 'template' => $str, 'enableVersioning' => $bool, 'confirm' => $bool], ['handle']),
            ],
            'delete_section' => [
                'description' => 'Delete a section by handle (admin, confirm). Deletes all its entries.',
                'risk' => self::RISK_SCHEMA_WRITE,
                'handler' => [StructureTool::class, 'deleteSection'],
                'inputSchema' => $obj(['handle' => $str, 'confirm' => $bool], ['handle']),
            ],

            // ---- Users (system-write) ----------------------------------------
            'list_users' => [
                'description' => 'List users. Args: group, search, limit, offset. Requires editUsers.',
                'risk' => self::RISK_READ,
                'handler' => [UsersTool::class, 'listUsers'],
                'inputSchema' => $obj(['group' => $str, 'search' => $str, 'limit' => $int, 'offset' => $int]),
            ],
            'get_user' => [
                'description' => 'Get a user by id or email. Requires editUsers.',
                'risk' => self::RISK_READ,
                'handler' => [UsersTool::class, 'getUser'],
                'inputSchema' => $obj(['id' => $int, 'email' => $str]),
            ],
            'list_user_groups' => [
                'description' => 'List user groups.',
                'risk' => self::RISK_READ,
                'handler' => [UsersTool::class, 'listUserGroups'],
                'inputSchema' => $obj(),
            ],
            'create_user' => [
                'description' => 'Create a user (admin, confirm). Args: email, username, firstName, lastName, groups (handles), fields, activate.',
                'risk' => self::RISK_SYSTEM_WRITE,
                'handler' => [UsersTool::class, 'createUser'],
                'inputSchema' => $obj(['email' => $str, 'username' => $str, 'firstName' => $str, 'lastName' => $str, 'groups' => ['type' => 'array'], 'fields' => $fields, 'activate' => $bool, 'confirm' => $bool], ['email']),
            ],
            'update_user' => [
                'description' => 'Update a user (admin, confirm). Args: id or email, plus email/username/firstName/lastName/groups/fields.',
                'risk' => self::RISK_SYSTEM_WRITE,
                'handler' => [UsersTool::class, 'updateUser'],
                'inputSchema' => $obj(['id' => $int, 'email' => $str, 'username' => $str, 'firstName' => $str, 'lastName' => $str, 'groups' => ['type' => 'array'], 'fields' => $fields, 'confirm' => $bool]),
            ],
            'delete_user' => [
                'description' => 'Delete a user (admin, confirm). Args: id or email.',
                'risk' => self::RISK_SYSTEM_WRITE,
                'handler' => [UsersTool::class, 'deleteUser'],
                'inputSchema' => $obj(['id' => $int, 'email' => $str, 'confirm' => $bool]),
            ],

            // ---- Project config (system-write) -------------------------------
            'project_config_get' => [
                'description' => 'Read project config at a dot-path (omit path for the whole tree). Use as the dry-run/diff step before project_config_set.',
                'risk' => self::RISK_READ,
                'handler' => [ProjectConfigTool::class, 'get'],
                'inputSchema' => $obj(['path' => $str]),
            ],
            'project_config_set' => [
                'description' => 'Set project config at a dot-path and apply the change (admin, confirm; requires allowAdminChanges). Args: path, value (null removes), message.',
                'risk' => self::RISK_SYSTEM_WRITE,
                'handler' => [ProjectConfigTool::class, 'set'],
                'inputSchema' => $obj(['path' => $str, 'value' => [], 'message' => $str, 'confirm' => $bool], ['path']),
            ],
            'project_config_apply' => [
                'description' => 'Apply pending external (YAML) project-config changes — like `craft up` (admin, confirm).',
                'risk' => self::RISK_SYSTEM_WRITE,
                'handler' => [ProjectConfigTool::class, 'apply'],
                'inputSchema' => $obj(['confirm' => $bool]),
            ],

            // ---- Plugins (system-write) --------------------------------------
            'list_plugins' => [
                'description' => 'List all plugins with installed/enabled state.',
                'risk' => self::RISK_READ,
                'handler' => [PluginsTool::class, 'listPlugins'],
                'inputSchema' => $obj(),
            ],
            'install_plugin' => [
                'description' => 'Install a Composer-present plugin by handle (admin, confirm).',
                'risk' => self::RISK_SYSTEM_WRITE,
                'handler' => [PluginsTool::class, 'installPlugin'],
                'inputSchema' => $obj(['handle' => $str, 'confirm' => $bool], ['handle']),
            ],
            'uninstall_plugin' => [
                'description' => 'Uninstall a plugin by handle (admin, confirm).',
                'risk' => self::RISK_SYSTEM_WRITE,
                'handler' => [PluginsTool::class, 'uninstallPlugin'],
                'inputSchema' => $obj(['handle' => $str, 'confirm' => $bool], ['handle']),
            ],
            'enable_plugin' => [
                'description' => 'Enable an installed plugin by handle (admin, confirm).',
                'risk' => self::RISK_SYSTEM_WRITE,
                'handler' => [PluginsTool::class, 'enablePlugin'],
                'inputSchema' => $obj(['handle' => $str, 'confirm' => $bool], ['handle']),
            ],
            'disable_plugin' => [
                'description' => 'Disable an installed plugin by handle (admin, confirm).',
                'risk' => self::RISK_SYSTEM_WRITE,
                'handler' => [PluginsTool::class, 'disablePlugin'],
                'inputSchema' => $obj(['handle' => $str, 'confirm' => $bool], ['handle']),
            ],

            // ---- GraphQL ------------------------------------------------------
            'graphql' => [
                'description' => 'Execute a GraphQL query (read). Args: query (required), variables, operationName, schemaUid.',
                'risk' => self::RISK_READ,
                'handler' => [GraphqlTool::class, 'execute'],
                'inputSchema' => $obj(['query' => $str, 'variables' => ['type' => 'object'], 'operationName' => $str, 'schemaUid' => $str], ['query']),
            ],

            // ---- Maintenance --------------------------------------------------
            'clear_caches' => [
                'description' => 'Clear caches. Args: which (array of data|compiled-templates|element-caches).',
                'risk' => self::RISK_CONTENT_WRITE,
                'handler' => [MaintenanceTool::class, 'clearCaches'],
                'inputSchema' => $obj(['which' => ['type' => 'array']]),
            ],
            'queue_status' => [
                'description' => 'Report queue status and jobs.',
                'risk' => self::RISK_READ,
                'handler' => [MaintenanceTool::class, 'queueStatus'],
                'inputSchema' => $obj(),
            ],
            'run_gc' => [
                'description' => 'Run Craft garbage collection (admin, confirm).',
                'risk' => self::RISK_SYSTEM_WRITE,
                'handler' => [MaintenanceTool::class, 'runGc'],
                'inputSchema' => $obj(['confirm' => $bool]),
            ],
        ];
    }
}

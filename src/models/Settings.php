<?php

namespace gerry3010\mcp\models;

use craft\base\Model;

/**
 * Plugin settings. The two write switches are safety kill-switches: even a valid
 * admin token cannot perform schema/system writes when the corresponding switch
 * is off. Per-operation `confirm` gating still applies on top of these.
 */
class Settings extends Model
{
    /** Master switch for the /mcp HTTP endpoint. */
    public bool $enabled = true;

    /** Allow schema-write tools (fields, entry types, sections). */
    public bool $allowSchemaWrite = true;

    /** Allow system-write tools (users, project config, plugins, gc). */
    public bool $allowSystemWrite = true;

    public function rules(): array
    {
        return [
            [['enabled', 'allowSchemaWrite', 'allowSystemWrite'], 'boolean'],
        ];
    }
}

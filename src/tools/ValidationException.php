<?php

namespace gerry3010\mcp\tools;

use RuntimeException;

/**
 * Thrown when a Craft element fails to save. Carries the per-field validation
 * errors so the MCP client sees exactly why a write was rejected.
 */
class ValidationException extends RuntimeException
{
    /** @var array<string, string[]> */
    public array $errors;

    /**
     * @param array<string, string[]> $errors
     */
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }
}

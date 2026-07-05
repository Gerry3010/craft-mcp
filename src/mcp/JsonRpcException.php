<?php

namespace gerry3010\mcp\mcp;

use RuntimeException;

/**
 * A JSON-RPC protocol-level error (parse/invalid-request/method-not-found/etc.).
 * The exception code is used verbatim as the JSON-RPC error code.
 */
class JsonRpcException extends RuntimeException
{
}

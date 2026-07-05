<?php

namespace gerry3010\mcp\tools;

use Craft;

/**
 * Execute a GraphQL query against Craft's schema — a powerful read surface for
 * arbitrary content queries. Uses the named schema by UID if given, otherwise
 * the public schema.
 */
class GraphqlTool
{
    public static function execute(array $args): array
    {
        $query = $args['query'] ?? null;
        if (!$query || !is_string($query)) {
            throw new \RuntimeException('graphql requires a string "query"');
        }
        $variables = $args['variables'] ?? null;
        if ($variables !== null && !is_array($variables)) {
            throw new \RuntimeException('graphql "variables" must be an object');
        }

        $gql = Craft::$app->getGql();
        $schema = null;
        if (!empty($args['schemaUid'])) {
            $schema = $gql->getSchemaByUid($args['schemaUid']);
            if (!$schema) {
                throw new \RuntimeException("GraphQL schema not found: {$args['schemaUid']}");
            }
        } else {
            $schema = $gql->getPublicSchema();
            if (!$schema) {
                throw new \RuntimeException(
                    'No public GraphQL schema is enabled. Provide "schemaUid" or enable the public schema.'
                );
            }
        }

        $result = $gql->executeQuery(
            $schema,
            $query,
            $variables,
            $args['operationName'] ?? null
        );

        return $result;
    }
}

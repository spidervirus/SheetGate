<?php

namespace SheetGate;

class McpServer
{
    private Cache $cache;
    private GoogleOAuth $oauth;

    public function __construct(Cache $cache, GoogleOAuth $oauth)
    {
        $this->cache = $cache;
        $this->oauth = $oauth;
    }

    /**
     * Handle incoming MCP JSON-RPC 2.0 request.
     *
     * @param array $endpoint
     * @param string $rawInput
     * @return array
     */
    public function handle(array $endpoint, string $rawInput): array
    {
        $payload = json_decode($rawInput, true);

        if (!$payload || !isset($payload['jsonrpc']) || $payload['jsonrpc'] !== '2.0') {
            return $this->errorResponse(null, -32600, 'Invalid Request: Expected JSON-RPC 2.0 payload.');
        }

        $id = $payload['id'] ?? null;
        $method = $payload['method'] ?? '';
        $params = $payload['params'] ?? [];

        switch ($method) {
            case 'initialize':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'protocolVersion' => '2024-11-05',
                        'capabilities' => [
                            'tools' => ['listChanged' => false],
                        ],
                        'serverInfo' => [
                            'name' => 'sheetgate-' . $endpoint['id'],
                            'version' => '1.0.0',
                            'title' => 'SheetGate: ' . $endpoint['name'],
                        ],
                    ],
                ];

            case 'notifications/initialized':
            case 'initialized':
                if ($id !== null) {
                    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => []];
                }
                return [];

            case 'ping':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['status' => 'pong']];

            case 'tools/list':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'tools' => $this->getToolsSchema($endpoint),
                    ],
                ];

            case 'tools/call':
                $toolName = $params['name'] ?? '';
                $arguments = $params['arguments'] ?? [];
                return $this->executeTool($id, $endpoint, $toolName, $arguments);

            default:
                return $this->errorResponse($id, -32601, "Method '{$method}' not found.");
        }
    }

    /**
     * Return the schema definitions for exactly the 3 read-only tools.
     */
    public function getToolsSchema(array $endpoint): array
    {
        return [
            [
                'name' => 'list_tabs',
                'description' => "List all worksheets/tabs available in the Google Sheet for '{$endpoint['name']}'.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_schema',
                'description' => "Get column headers, detected data types, row count, and sample data for the active sheet '{$endpoint['name']}'.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'include_samples' => [
                            'type' => 'boolean',
                            'description' => 'Whether to include top 3 sample rows in the schema report (default true).',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'query_rows',
                'description' => "Query and search records from '{$endpoint['name']}'. Supports filters, full-text search, sorting, pagination, column selection, and aggregates.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => [
                            'type' => 'string',
                            'description' => 'Full-text search query across all columns.',
                        ],
                        'filters' => [
                            'type' => 'object',
                            'description' => 'Key-value map of column filters. Examples: {"status": "active"} or {"price": "gt.100", "category": "contains.tech"}. Available operators: eq, neq, gt, gte, lt, lte, contains, not_contains, starts_with, ends_with, in.',
                        ],
                        'sort' => [
                            'type' => 'string',
                            'description' => 'Column name to sort by.',
                        ],
                        'order' => [
                            'type' => 'string',
                            'enum' => ['asc', 'desc'],
                            'description' => 'Sort direction (asc or desc, default asc).',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum rows to return (default 20, max 200).',
                        ],
                        'offset' => [
                            'type' => 'integer',
                            'description' => 'Row offset for pagination (default 0).',
                        ],
                        'fields' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'List of specific columns to project.',
                        ],
                        'aggregate' => [
                            'type' => 'string',
                            'description' => 'Aggregate calculations, e.g. "count", "sum:price", "avg:rating", "min:date", "max:amount".',
                        ],
                        'group_by' => [
                            'type' => 'string',
                            'description' => 'Group rows by a column before aggregating.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    /**
     * Execute MCP tool invocation.
     */
    private function executeTool(mixed $id, array $endpoint, string $toolName, array $arguments): array
    {
        try {
            switch ($toolName) {
                case 'list_tabs':
                    $token = null;
                    if (($endpoint['source_type'] ?? '') === 'google_oauth') {
                        $token = $this->oauth->getValidAccessToken();
                    }
                    $tabs = Fetcher::fetchTabs($endpoint['spreadsheet_id'], $token);

                    $content = [
                        'spreadsheet_id' => $endpoint['spreadsheet_id'],
                        'current_tab_gid' => $endpoint['sheet_gid'],
                        'tabs' => $tabs,
                    ];
                    return $this->successResponse($id, json_encode($content, JSON_PRETTY_PRINT));

                case 'get_schema':
                    $sheet = $this->cache->getOrFetch($endpoint);
                    $includeSamples = $arguments['include_samples'] ?? true;

                    $schema = [
                        'endpoint_id' => $endpoint['id'],
                        'endpoint_name' => $endpoint['name'],
                        'total_rows' => $sheet['count'],
                        'columns' => [],
                        'cache_status' => $sheet['cache_status'],
                        'last_cached_at' => date('c', $sheet['cached_at']),
                    ];

                    foreach ($sheet['headers'] as $col) {
                        $schema['columns'][] = [
                            'name' => $col,
                            'type' => $sheet['types'][$col] ?? 'text',
                        ];
                    }

                    if ($includeSamples && !empty($sheet['rows'])) {
                        $schema['sample_rows'] = array_slice($sheet['rows'], 0, 3);
                    }

                    return $this->successResponse($id, json_encode($schema, JSON_PRETTY_PRINT));

                case 'query_rows':
                    $sheet = $this->cache->getOrFetch($endpoint);

                    // Build query params array for QueryEngine
                    $queryParams = [];
                    if (!empty($arguments['search'])) {
                        $queryParams['search'] = (string)$arguments['search'];
                    }
                    if (!empty($arguments['sort'])) {
                        $queryParams['sort'] = (string)$arguments['sort'];
                    }
                    if (!empty($arguments['order'])) {
                        $queryParams['order'] = (string)$arguments['order'];
                    }
                    $queryParams['limit'] = isset($arguments['limit']) ? min(200, (int)$arguments['limit']) : 20;
                    $queryParams['offset'] = isset($arguments['offset']) ? max(0, (int)$arguments['offset']) : 0;

                    if (!empty($arguments['fields']) && is_array($arguments['fields'])) {
                        $queryParams['fields'] = implode(',', $arguments['fields']);
                    }
                    if (!empty($arguments['aggregate'])) {
                        $queryParams['aggregate'] = (string)$arguments['aggregate'];
                    }
                    if (!empty($arguments['group_by'])) {
                        $queryParams['group_by'] = (string)$arguments['group_by'];
                    }

                    // Filters
                    if (!empty($arguments['filters']) && is_array($arguments['filters'])) {
                        foreach ($arguments['filters'] as $col => $cond) {
                            $queryParams[$col] = $cond;
                        }
                    }

                    $res = QueryEngine::process($sheet['rows'], $queryParams, $sheet['headers']);
                    $res['cache_status'] = $sheet['cache_status'];

                    return $this->successResponse($id, json_encode($res, JSON_PRETTY_PRINT));

                default:
                    return $this->errorResponse($id, -32601, "Unknown tool: '{$toolName}'. Available tools: list_tabs, get_schema, query_rows.");
            }
        } catch (\Exception $e) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Error: ' . $e->getMessage(),
                        ],
                    ],
                    'isError' => true,
                ],
            ];
        }
    }

    private function successResponse(mixed $id, string $text): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
            ],
        ];
    }

    private function errorResponse(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}

<?php

namespace SheetGate;

class QueryEngine
{
    private const RESERVED_PARAMS = [
        'sort', 'order', 'limit', 'offset', 'fields', 'search',
        'aggregate', 'aggregates', 'group_by', 'api_key', 'format'
    ];

    /**
     * Execute filtering, searching, sorting, pagination, projection, and aggregations on row dataset.
     *
     * @param array<array<string, mixed>> $rows
     * @param array<string, mixed> $queryParams
     * @param array<string> $availableHeaders
     * @return array<string, mixed>
     */
    public static function process(array $rows, array $queryParams, array $availableHeaders): array
    {
        $filtered = self::applyFiltersAndSearch($rows, $queryParams, $availableHeaders);
        $total = count($filtered);

        // Check if aggregates or group_by are requested
        $aggregatesParam = $queryParams['aggregate'] ?? $queryParams['aggregates'] ?? null;
        $groupByParam = $queryParams['group_by'] ?? null;

        $aggregateResults = null;
        $groupResults = null;

        if ($groupByParam) {
            $groupResults = self::computeGroups($filtered, $groupByParam, $aggregatesParam);
        } elseif ($aggregatesParam) {
            $aggregateResults = self::computeAggregates($filtered, $aggregatesParam);
        }

        // Sorting
        $sorted = self::applySorting($filtered, $queryParams);

        // Pagination
        $limit = isset($queryParams['limit']) ? max(1, min((int)$queryParams['limit'], 1000)) : 100;
        $offset = isset($queryParams['offset']) ? max(0, (int)$queryParams['offset']) : 0;
        $paged = array_slice($sorted, $offset, $limit);

        // Column selection (fields=)
        if (!empty($queryParams['fields'])) {
            $fields = array_map('trim', explode(',', (string)$queryParams['fields']));
            $fields = array_filter($fields, fn($f) => in_array($f, $availableHeaders, true));

            if (!empty($fields)) {
                $paged = array_map(function ($row) use ($fields) {
                    $projected = [];
                    foreach ($fields as $f) {
                        $projected[$f] = $row[$f] ?? null;
                    }
                    return $projected;
                }, $paged);
            }
        }

        $response = [
            'data' => $paged,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($aggregateResults !== null) {
            $response['aggregates'] = $aggregateResults;
        }

        if ($groupResults !== null) {
            $response['groups'] = $groupResults;
            $response['total_groups'] = count($groupResults);
        }

        return $response;
    }

    /**
     * Filter rows based on query parameters and search.
     */
    public static function applyFiltersAndSearch(array $rows, array $queryParams, array $availableHeaders): array
    {
        $headerMap = [];
        foreach ($availableHeaders as $h) {
            $headerMap[strtolower($h)] = $h;
        }

        // Parse column filters
        $filters = [];
        foreach ($queryParams as $key => $value) {
            if (in_array(strtolower($key), self::RESERVED_PARAMS, true)) {
                continue;
            }

            $lowerKey = strtolower($key);
            if (!isset($headerMap[$lowerKey])) {
                continue;
            }
            $actualCol = $headerMap[$lowerKey];

            // Value can be array syntax ?status[eq]=active or operator prefix ?status=eq.active
            if (is_array($value)) {
                foreach ($value as $op => $targetVal) {
                    $filters[] = [
                        'column' => $actualCol,
                        'operator' => strtolower($op),
                        'value' => $targetVal,
                    ];
                }
            } elseif (is_string($value)) {
                // Check operator prefix e.g. "gt.25", "contains.abc"
                if (preg_match('/^(eq|neq|ne|gt|gte|lt|lte|contains|not_contains|starts_with|ends_with|in|not_in|is_null|not_null)\.(.*)$/i', $value, $m)) {
                    $filters[] = [
                        'column' => $actualCol,
                        'operator' => strtolower($m[1]),
                        'value' => $m[2],
                    ];
                } else {
                    // Default equals
                    $filters[] = [
                        'column' => $actualCol,
                        'operator' => 'eq',
                        'value' => $value,
                    ];
                }
            }
        }

        $searchQuery = isset($queryParams['search']) ? trim((string)$queryParams['search']) : '';

        if (empty($filters) && $searchQuery === '') {
            return $rows;
        }

        return array_values(array_filter($rows, function ($row) use ($filters, $searchQuery) {
            // Apply column filters
            foreach ($filters as $f) {
                if (!self::matchFilter($row[$f['column']] ?? null, $f['operator'], $f['value'])) {
                    return false;
                }
            }

            // Apply full-text search
            if ($searchQuery !== '') {
                $matchedSearch = false;
                $searchLower = mb_strtolower($searchQuery);
                foreach ($row as $val) {
                    if ($val === null) {
                        continue;
                    }
                    $strVal = is_string($val) ? $val : json_encode($val);
                    if (mb_stripos($strVal, $searchLower) !== false) {
                        $matchedSearch = true;
                        break;
                    }
                }
                if (!$matchedSearch) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Check if a row value matches an operator and target value.
     */
    private static function matchFilter(mixed $actual, string $op, mixed $target): bool
    {
        $op = strtolower($op);

        if ($op === 'is_null') {
            return $actual === null || $actual === '';
        }
        if ($op === 'not_null') {
            return $actual !== null && $actual !== '';
        }

        if ($actual === null) {
            return false;
        }

        switch ($op) {
            case 'eq':
                return strcasecmp((string)$actual, (string)$target) === 0;

            case 'neq':
            case 'ne':
                return strcasecmp((string)$actual, (string)$target) !== 0;

            case 'gt':
                return is_numeric($actual) && is_numeric($target)
                    ? ((float)$actual > (float)$target)
                    : (strcmp((string)$actual, (string)$target) > 0);

            case 'gte':
                return is_numeric($actual) && is_numeric($target)
                    ? ((float)$actual >= (float)$target)
                    : (strcmp((string)$actual, (string)$target) >= 0);

            case 'lt':
                return is_numeric($actual) && is_numeric($target)
                    ? ((float)$actual < (float)$target)
                    : (strcmp((string)$actual, (string)$target) < 0);

            case 'lte':
                return is_numeric($actual) && is_numeric($target)
                    ? ((float)$actual <= (float)$target)
                    : (strcmp((string)$actual, (string)$target) <= 0);

            case 'contains':
                return mb_stripos((string)$actual, (string)$target) !== false;

            case 'not_contains':
                return mb_stripos((string)$actual, (string)$target) === 0 ? false : (mb_stripos((string)$actual, (string)$target) === false);

            case 'starts_with':
                return str_starts_with(mb_strtolower((string)$actual), mb_strtolower((string)$target));

            case 'ends_with':
                return str_ends_with(mb_strtolower((string)$actual), mb_strtolower((string)$target));

            case 'in':
                $items = is_array($target) ? $target : explode(',', (string)$target);
                $items = array_map(fn($v) => mb_strtolower(trim((string)$v)), $items);
                return in_array(mb_strtolower((string)$actual), $items, true);

            case 'not_in':
                $items = is_array($target) ? $target : explode(',', (string)$target);
                $items = array_map(fn($v) => mb_strtolower(trim((string)$v)), $items);
                return !in_array(mb_strtolower((string)$actual), $items, true);

            default:
                return strcasecmp((string)$actual, (string)$target) === 0;
        }
    }

    /**
     * Apply sorting to filtered rows.
     */
    public static function applySorting(array $rows, array $queryParams): array
    {
        $sort = $queryParams['sort'] ?? null;
        $order = strtolower($queryParams['order'] ?? 'asc');

        if (!$sort) {
            return $rows;
        }

        // Support syntax "?sort=-price" or "?sort=price:desc"
        if (str_starts_with($sort, '-')) {
            $sort = substr($sort, 1);
            $order = 'desc';
        } elseif (str_contains($sort, ':')) {
            [$sort, $customOrder] = explode(':', $sort, 2);
            $order = strtolower(trim($customOrder));
        }

        $isDesc = ($order === 'desc');

        usort($rows, function ($a, $b) use ($sort, $isDesc) {
            $valA = $a[$sort] ?? null;
            $valB = $b[$sort] ?? null;

            if ($valA === $valB) {
                return 0;
            }
            if ($valA === null) {
                return 1;
            }
            if ($valB === null) {
                return -1;
            }

            if (is_numeric($valA) && is_numeric($valB)) {
                $res = ((float)$valA < (float)$valB) ? -1 : 1;
            } else {
                $res = strcasecmp((string)$valA, (string)$valB);
            }

            return $isDesc ? -$res : $res;
        });

        return $rows;
    }

    /**
     * Compute aggregated metrics: count, sum, avg, min, max.
     */
    public static function computeAggregates(array $rows, string $aggregatesParam): array
    {
        $specs = array_map('trim', explode(',', $aggregatesParam));
        $results = [];

        foreach ($specs as $spec) {
            if ($spec === 'count') {
                $results['count'] = count($rows);
                continue;
            }

            if (!str_contains($spec, ':')) {
                continue;
            }

            [$func, $col] = explode(':', $spec, 2);
            $func = strtolower(trim($func));
            $col = trim($col);

            $values = [];
            foreach ($rows as $r) {
                $v = $r[$col] ?? null;
                if ($v !== null && is_numeric($v)) {
                    $values[] = (float)$v;
                }
            }

            $key = "{$func}_{$col}";
            $count = count($values);

            switch ($func) {
                case 'sum':
                    $results[$key] = array_sum($values);
                    break;
                case 'avg':
                    $results[$key] = $count > 0 ? round(array_sum($values) / $count, 4) : 0;
                    break;
                case 'min':
                    $results[$key] = $count > 0 ? min($values) : null;
                    break;
                case 'max':
                    $results[$key] = $count > 0 ? max($values) : null;
                    break;
                case 'count':
                    $results[$key] = $count;
                    break;
            }
        }

        return $results;
    }

    /**
     * Group rows and compute aggregate statistics per group.
     */
    public static function computeGroups(array $rows, string $groupColumn, ?string $aggregatesParam): array
    {
        $grouped = [];
        foreach ($rows as $r) {
            $groupVal = (string)($r[$groupColumn] ?? 'null');
            $grouped[$groupVal][] = $r;
        }

        $groupList = [];
        foreach ($grouped as $groupVal => $groupRows) {
            $item = [
                'group_by' => $groupColumn,
                'value' => $groupVal,
                'count' => count($groupRows),
            ];

            if ($aggregatesParam) {
                $item['aggregates'] = self::computeAggregates($groupRows, $aggregatesParam);
            }

            $groupList[] = $item;
        }

        return $groupList;
    }
}

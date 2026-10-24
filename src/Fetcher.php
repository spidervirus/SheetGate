<?php

namespace SheetGate;

class Fetcher
{
    private const ALLOWED_HOSTS = [
        'docs.google.com',
        'sheets.googleapis.com',
    ];

    /**
     * Parse a Google Sheets URL and extract spreadsheet ID and gid.
     *
     * @param string $url
     * @return array{spreadsheet_id: string, gid: string, is_published: bool}
     * @throws \InvalidArgumentException
     */
    public static function parseUrl(string $url): array
    {
        $url = trim($url);
        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['host'])) {
            throw new \InvalidArgumentException('Invalid Google Sheets URL.');
        }

        if (!in_array(strtolower($parsed['host']), self::ALLOWED_HOSTS, true)) {
            throw new \InvalidArgumentException('SSRF Protection: Only docs.google.com URLs are permitted.');
        }

        // Match standard or published spreadsheet ID
        // e.g. /spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/
        // or /spreadsheets/d/e/2PACX-1v.../
        $isPublished = false;
        $spreadsheetId = '';

        if (preg_match('#/spreadsheets/d/e/([a-zA-Z0-9_-]+)#', $url, $matches)) {
            $isPublished = true;
            $spreadsheetId = $matches[1];
        } elseif (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $matches)) {
            $spreadsheetId = $matches[1];
        } else {
            throw new \InvalidArgumentException('Could not extract Spreadsheet ID from URL.');
        }

        // Extract gid from fragment or query string
        $gid = '0';
        if (preg_match('/[#?&]gid=([0-9]+)/', $url, $matches)) {
            $gid = $matches[1];
        }

        return [
            'spreadsheet_id' => $spreadsheetId,
            'gid' => $gid,
            'is_published' => $isPublished,
        ];
    }

    /**
     * Build the direct CSV export URL.
     */
    public static function buildCsvExportUrl(string $spreadsheetId, string $gid = '0', bool $isPublished = false): string
    {
        if ($isPublished) {
            return "https://docs.google.com/spreadsheets/d/e/{$spreadsheetId}/pub?output=csv&gid={$gid}";
        }
        return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=csv&gid={$gid}";
    }

    /**
     * Alternative GViz CSV export URL.
     */
    public static function buildGvizCsvUrl(string $spreadsheetId, string $gid = '0'): string
    {
        return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/gviz/tq?tqx=out:csv&gid={$gid}";
    }

    /**
     * Fetch public sheet data via CSV export.
     *
     * @param string $spreadsheetId
     * @param string $gid
     * @param bool $isPublished
     * @return array{headers: array<string>, types: array<string, string>, rows: array<array<string, mixed>>, count: int}
     */
    public static function fetchPublicCsv(string $spreadsheetId, string $gid = '0', bool $isPublished = false): array
    {
        $primaryUrl = self::buildCsvExportUrl($spreadsheetId, $gid, $isPublished);
        $csvContent = null;
        $lastError = null;

        try {
            $csvContent = self::executeSafeCurl($primaryUrl);
        } catch (\Exception $e) {
            $lastError = $e->getMessage();
            // Try GViz fallback for non-published docs
            if (!$isPublished) {
                try {
                    $fallbackUrl = self::buildGvizCsvUrl($spreadsheetId, $gid);
                    $csvContent = self::executeSafeCurl($fallbackUrl);
                } catch (\Exception $e2) {
                    throw new \RuntimeException(
                        "Failed to fetch Google Sheet: {$e->getMessage()}. Fallback also failed: {$e2->getMessage()}"
                    );
                }
            } else {
                throw new \RuntimeException("Failed to fetch Google Sheet: {$lastError}");
            }
        }

        if (empty(trim($csvContent))) {
            throw new \RuntimeException('Google Sheet returned an empty CSV response. Check sheet sharing permissions (Must be set to "Anyone with the link can view").');
        }

        // Detect if Google returned an HTML login or error page instead of CSV
        $trimmed = ltrim($csvContent);
        if (str_starts_with($trimmed, '<!DOCTYPE') || str_starts_with($trimmed, '<html')) {
            throw new \RuntimeException('Google Sheet is restricted or private. Make sure "General access" is set to "Anyone with the link can view", or connect Google OAuth in SheetGate.');
        }

        return self::parseCsvData($csvContent);
    }

    /**
     * Fetch restricted sheet data via Google Sheets REST API.
     *
     * @param string $spreadsheetId
     * @param string $sheetTitleOrRange
     * @param string $accessToken
     * @return array{headers: array<string>, types: array<string, string>, rows: array<array<string, mixed>>, count: int}
     */
    public static function fetchViaApi(string $spreadsheetId, string $sheetTitleOrRange, string $accessToken): array
    {
        $range = urlencode($sheetTitleOrRange ?: 'Sheet1');
        $apiUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}?valueRenderOption=UNFORMATTED_VALUE";

        $response = self::executeSafeCurl($apiUrl, [
            "Authorization: Bearer {$accessToken}",
            "Accept: application/json",
        ]);

        $data = json_decode($response, true);
        if (!$data || !isset($data['values'])) {
            throw new \RuntimeException('Google Sheets API did not return values. Check permissions and sheet tab name.');
        }

        $values = $data['values'];
        if (empty($values)) {
            return [
                'headers' => [],
                'types' => [],
                'rows' => [],
                'count' => 0,
            ];
        }

        // Header row
        $rawHeaders = array_shift($values);
        $headers = self::sanitizeHeaders($rawHeaders);

        $rows = [];
        foreach ($values as $rowValues) {
            $row = [];
            foreach ($headers as $index => $colName) {
                $row[$colName] = $rowValues[$index] ?? null;
            }
            $rows[] = $row;
        }

        $types = self::inferColumnTypes($headers, $rows);

        // Normalize typed values
        foreach ($rows as &$r) {
            foreach ($types as $col => $type) {
                $r[$col] = self::castValue($r[$col] ?? null, $type);
            }
        }
        unset($r);

        return [
            'headers' => $headers,
            'types' => $types,
            'rows' => $rows,
            'count' => count($rows),
        ];
    }

    /**
     * Fetch sheet tabs list (metadata) via Google Sheets API or web discovery.
     *
     * @param string $spreadsheetId
     * @param string|null $accessToken
     * @return array<array{gid: string, title: string, index: int}>
     */
    public static function fetchTabs(string $spreadsheetId, ?string $accessToken = null): array
    {
        if ($accessToken) {
            $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}?fields=sheets.properties";
            $json = self::executeSafeCurl($url, [
                "Authorization: Bearer {$accessToken}",
                "Accept: application/json",
            ]);
            $data = json_decode($json, true);
            $tabs = [];
            if (isset($data['sheets']) && is_array($data['sheets'])) {
                foreach ($data['sheets'] as $idx => $sheet) {
                    $props = $sheet['properties'] ?? [];
                    $tabs[] = [
                        'gid' => (string)($props['sheetId'] ?? '0'),
                        'title' => (string)($props['title'] ?? "Sheet{$idx}"),
                        'index' => (int)($props['index'] ?? $idx),
                    ];
                }
            }
            return $tabs;
        }

        // For public sheet, attempt to scrape sheet tabs from public HTML or fallback to gid 0
        $htmlUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/htmlview";
        try {
            $html = self::executeSafeCurl($htmlUrl);
            $tabs = [];
            // Match tab buttons e.g. <li id="sheet-button-0"><a href="#0">TabName</a>
            // or item: {"name":"Sheet1","sheetId":0}
            if (preg_match_all('/id="sheet-button-([0-9]+)"[^>]*>.*?<a[^>]*>([^<]+)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $idx => $m) {
                    $tabs[] = [
                        'gid' => trim($m[1]),
                        'title' => html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'index' => $idx,
                    ];
                }
            }

            if (!empty($tabs)) {
                return $tabs;
            }
        } catch (\Exception $e) {
            // Ignore and return default
        }

        return [
            ['gid' => '0', 'title' => 'Sheet1', 'index' => 0],
        ];
    }

    /**
     * Parse raw CSV content into clean headers, auto-typed rows, and type definitions.
     *
     * @param string $csvContent
     * @return array{headers: array<string>, types: array<string, string>, rows: array<array<string, mixed>>, count: int}
     */
    public static function parseCsvData(string $csvContent): array
    {
        // Strip UTF-8 BOM if present
        if (str_starts_with($csvContent, "\xEF\xBB\xBF")) {
            $csvContent = substr($csvContent, 3);
        }

        // Open in-memory stream for robust RFC 4180 parsing
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csvContent);
        rewind($stream);

        $headers = [];
        $rawRows = [];

        // Read first non-empty row as header
        while (($row = fgetcsv($stream)) !== false) {
            // Check if all fields are empty
            if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) {
                continue;
            }
            $headers = self::sanitizeHeaders($row);
            break;
        }

        if (empty($headers)) {
            fclose($stream);
            return [
                'headers' => [],
                'types' => [],
                'rows' => [],
                'count' => 0,
            ];
        }

        // Read remaining rows
        $headerCount = count($headers);
        while (($row = fgetcsv($stream)) !== false) {
            if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) {
                continue; // Skip entirely empty rows
            }

            $record = [];
            for ($i = 0; $i < $headerCount; $i++) {
                $record[$headers[$i]] = isset($row[$i]) ? trim($row[$i]) : null;
            }
            $rawRows[] = $record;
        }
        fclose($stream);

        // Type inference
        $types = self::inferColumnTypes($headers, $rawRows);

        // Convert values according to detected types
        $typedRows = [];
        foreach ($rawRows as $r) {
            $item = [];
            foreach ($headers as $col) {
                $item[$col] = self::castValue($r[$col] ?? null, $types[$col]);
            }
            $typedRows[] = $item;
        }

        return [
            'headers' => $headers,
            'types' => $types,
            'rows' => $typedRows,
            'count' => count($typedRows),
        ];
    }

    /**
     * Sanitize header names to valid JSON keys, handling duplicates and empty strings.
     *
     * @param array<mixed> $rawHeaders
     * @return array<string>
     */
    public static function sanitizeHeaders(array $rawHeaders): array
    {
        $headers = [];
        $seen = [];

        foreach ($rawHeaders as $index => $raw) {
            $clean = trim((string)$raw);
            if ($clean === '') {
                $clean = 'column_' . ($index + 1);
            }

            // Replace spaces and special characters with underscores, or keep friendly
            $clean = preg_replace('/[^\p{L}\p{N}_-]/u', '_', $clean);
            $clean = trim($clean, '_');
            if ($clean === '') {
                $clean = 'column_' . ($index + 1);
            }

            // Deduplicate column names
            $base = $clean;
            $count = 1;
            while (isset($seen[strtolower($clean)])) {
                $clean = "{$base}_{$count}";
                $count++;
            }

            $seen[strtolower($clean)] = true;
            $headers[] = $clean;
        }

        return $headers;
    }

    /**
     * Infer column types from row values.
     * Types: 'number', 'boolean', 'date', 'text'
     *
     * @param array<string> $headers
     * @param array<array<string, mixed>> $rows
     * @return array<string, string>
     */
    public static function inferColumnTypes(array $headers, array $rows): array
    {
        $types = [];

        // Sample up to 100 rows for type inference
        $sampleSize = min(count($rows), 100);
        $sample = array_slice($rows, 0, $sampleSize);

        foreach ($headers as $col) {
            $isNumber = true;
            $isBoolean = true;
            $isDate = true;
            $nonEmptyCount = 0;

            foreach ($sample as $row) {
                $val = $row[$col] ?? null;
                if ($val === null || $val === '') {
                    continue;
                }
                $nonEmptyCount++;
                $valStr = is_string($val) ? trim($val) : (string)$val;

                // Check boolean
                $lower = strtolower($valStr);
                if (!in_array($lower, ['true', 'false', 'yes', 'no', '1', '0', 'y', 'n'], true)) {
                    $isBoolean = false;
                }

                // Check number
                // Strip currency symbols and commas if standard number e.g. "$1,200.50"
                $cleanNum = preg_replace('/^[\$€£¥₹]/', '', $valStr);
                $cleanNum = str_replace(',', '', $cleanNum);
                if (!is_numeric($cleanNum)) {
                    $isNumber = false;
                }

                // Check date
                if ($isDate) {
                    if (is_numeric($valStr) || strlen($valStr) < 4 || !self::isValidDate($valStr)) {
                        $isDate = false;
                    }
                }
            }

            if ($nonEmptyCount === 0) {
                $types[$col] = 'text';
            } elseif ($isBoolean) {
                $types[$col] = 'boolean';
            } elseif ($isNumber) {
                $types[$col] = 'number';
            } elseif ($isDate) {
                $types[$col] = 'date';
            } else {
                $types[$col] = 'text';
            }
        }

        return $types;
    }

    private static function isValidDate(string $val): bool
    {
        // Don't classify pure integers like years "2024" as date if it could be a number
        if (preg_match('/^\d{4}$/', $val)) {
            return false;
        }
        $time = strtotime($val);
        return $time !== false && $time > 0;
    }

    /**
     * Cast string value to detected PHP type.
     */
    public static function castValue(mixed $val, string $type): mixed
    {
        if ($val === null || $val === '') {
            return null;
        }

        $str = is_string($val) ? trim($val) : (string)$val;

        switch ($type) {
            case 'boolean':
                $lower = strtolower($str);
                return in_array($lower, ['true', 'yes', '1', 'y'], true);

            case 'number':
                $clean = preg_replace('/^[\$€£¥₹]/', '', $str);
                $clean = str_replace(',', '', $clean);
                if (is_numeric($clean)) {
                    return strpos($clean, '.') !== false ? (float)$clean : (int)$clean;
                }
                return $val;

            case 'date':
                $timestamp = strtotime($str);
                if ($timestamp !== false) {
                    return date('Y-m-d H:i:s', $timestamp);
                }
                return $str;

            case 'text':
            default:
                return $val;
        }
    }

    /**
     * Execute cURL with strict SSRF protection and timeouts.
     *
     * @param string $url
     * @param array<string> $headers
     * @return string
     * @throws \RuntimeException
     */
    public static function executeSafeCurl(string $url, array $headers = []): string
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('Invalid URL provided.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new \RuntimeException('SSRF Protection: Only HTTPS scheme is permitted.');
        }

        $host = strtolower($parts['host']);
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new \RuntimeException("SSRF Protection: Host '{$host}' is not permitted.");
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SheetGate/1.0; +https://github.com/sheetgate/sheetgate)',
            CURLOPT_HTTPHEADER => array_merge([
                'Accept: text/csv, application/json, text/html, */*',
                'Accept-Encoding: gzip, deflate',
            ], $headers),
            CURLOPT_ENCODING => '', // Enable all supported compression formats
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException("cURL error: {$curlError}");
        }

        // Verify effective redirect URL host for SSRF
        $effectiveHost = parse_url($effectiveUrl, PHP_URL_HOST);
        if ($effectiveHost && !in_array(strtolower($effectiveHost), self::ALLOWED_HOSTS, true)) {
            throw new \RuntimeException("SSRF Protection: Redirect to unauthorized host '{$effectiveHost}' blocked.");
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("Google returned HTTP {$httpCode} status code.");
        }

        return (string)$body;
    }
}

# SheetGate ⚡

> **Turn any Google Sheet into a fast, cached, read-only JSON REST API and Model Context Protocol (MCP) server for AI agents.**

SheetGate is an open source, self-hosted developer tool that converts Google Sheets into high-performance endpoints. Query your sheets with full-text search, multi-operator filters, sorting, pagination, and aggregates from any web app, mobile app, no-code tool, or AI assistant (such as **Claude Desktop**, **Cursor**, or **Windsurf**).

Built with **modern vanilla PHP 8.2+ and SQLite via PDO**, SheetGate requires **zero Node.js processes, no Docker, and no expensive SaaS subscriptions**. It runs effortlessly on a small VPS, shared cPanel hosting, or locally.

---

## Features

- **Public & Restricted Sheets**: Read public Google Sheets instantly via CSV export with strict SSRF protection, or authenticate private sheets using Google OAuth 2.0.
- **High-Performance SQLite Cache**: Configurable TTL (default 5 minutes, 30s minimum floor) with **Stale-While-Revalidate (SWR)** and request coalescing to eliminate cache stampedes on Google.
- **Rich REST Query Engine**:
  - Filtering by any column with intuitive operators: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `contains`, `not_contains`, `starts_with`, `ends_with`, `in`, `is_null`, `not_null`
  - Case-insensitive full-text search across all row fields (`?search=term`)
  - Sorting (`?sort=price&order=desc`) and pagination (`?limit=50&offset=0`)
  - Column projections (`?fields=id,name,price`)
  - Aggregations (`count`, `sum`, `avg`, `min`, `max`) and `group_by`
  - Automatic type inference (`number`, `boolean`, `date`, `text`)
- **Model Context Protocol (MCP) Server**:
  - Stateless HTTP JSON-RPC 2.0 endpoint at `/mcp/{endpoint_id}`
  - Three read-only tools: `list_tabs`, `get_schema`, `query_rows`
  - Pre-generated copy-paste configs for Claude Desktop (`claude_desktop_config.json`) and Cursor (`.cursor/mcp.json`)
- **Schema Drift Protection**:
  - Takes snapshots of column schemas on every sync
  - Detects added, dropped, renamed columns, and type changes
  - Optional **"Lock Schema"** mode that returns HTTP 409 error instead of serving an altered payload shape
  - Webhook notifications on detected schema shifts
- **Security & Access Control**:
  - Per-endpoint visibility: Public (open) or Protected with Bearer API keys (`sg_live_...`)
  - API keys stored securely hashed using SHA-256
  - OAuth tokens encrypted at rest with **Libsodium** (`sodium_crypto_secretbox`)
  - Per-endpoint IP sliding window rate limiting
  - Prepared statements with PDO SQLite everywhere
- **Sleek Admin UI**:
  - Dark-mode dashboard built with vanilla HTML5, CSS3, and modern JS (zero npm build step)
  - Interactive query playground with live JSON preview and table viewer
  - Snippet generators for cURL, JavaScript `fetch`, and Python `requests`

---

## Quick Start (Local Development)

### 1. Requirements
- PHP 8.2+ with `pdo_sqlite`, `curl`, and `sodium` extensions enabled (standard on macOS, Linux, and Windows PHP packages).

### 2. Clone and Run
```bash
git clone https://github.com/spidervirus/sheetgate.git
cd sheetgate

# Start PHP built-in web server
php -S 127.0.0.1:8080 -t public public/index.php
```

### 3. Open Admin Dashboard
Visit [http://localhost:8080/admin](http://localhost:8080/admin) in your browser. On your first visit, you will be prompted to set an admin password.

---

## Production Deployment

### Shared Hosting (cPanel / Apache)
1. Point your domain or subdomain DocumentRoot to the `/public` folder.
2. The included `public/.htaccess` routes all requests to `public/index.php` with authorization header forwarding.
3. The `/storage` directory is secured by `storage/.htaccess` with `Require all denied`.

### Nginx Configuration
```nginx
server {
    listen 80;
    server_name sheetgate.yourdomain.com;
    root /var/www/sheetgate/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\. {
        deny all;
    }
}
```

### Scheduled Refresh (Cron)
Add a crontab entry to refresh expiring endpoints in the background:
```bash
* * * * * php /var/www/sheetgate/bin/cron.php >> /var/log/sheetgate_cron.log 2>&1
```
Or trigger it via HTTP cron:
```
GET https://sheetgate.yourdomain.com/api/cron?key=YOUR_SHEETGATE_APP_KEY
```

---

## REST API Reference

### 1. Basic Query
```http
GET /api/{endpoint_id}
```
**Response:**
```json
{
  "data": [
    {
      "id": 101,
      "name": "Wireless Keyboard",
      "price": 89.99,
      "in_stock": true,
      "category": "Peripherals"
    }
  ],
  "total": 1,
  "limit": 100,
  "offset": 0
}
```

### 2. Filtering
Filter by column with equality or operators:
- **Equality**: `?status=active` or `?status=eq.active`
- **Inequality**: `?status=neq.archived`
- **Greater Than / Less Than**: `?price=gt.50` or `?age=lte.30`
- **Substring Match**: `?name=contains.keyboard` or `?email=ends_with.@gmail.com`
- **List Membership**: `?category=in.audio,electronics`
- **Null Checks**: `?notes=is_null` or `?email=not_null`

### 3. Full-Text Search
Search across all columns simultaneously (case-insensitive):
```http
GET /api/{endpoint_id}?search=wireless
```

### 4. Sorting & Pagination
```http
GET /api/{endpoint_id}?sort=price&order=desc&limit=25&offset=50
```

### 5. Projections (Select Columns)
```http
GET /api/{endpoint_id}?fields=id,name,price
```

### 6. Aggregations & Group By
Calculate summary metrics:
```http
GET /api/{endpoint_id}?aggregate=count,sum:price,avg:rating,min:price,max:price
```

Group by a categorical column:
```http
GET /api/{endpoint_id}?group_by=category&aggregate=sum:price,count
```

### 7. Cache Control Headers
- `X-Cache`: `HIT`, `MISS`, `STALE`
- `X-Cached-At`: ISO 8601 timestamp of cached data
- `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`

---

## Model Context Protocol (MCP) Setup

SheetGate acts as a full-featured Model Context Protocol server. AI agents can connect and query your sheets directly using structured tools.

### Claude Desktop Setup
Open `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows) and add:

```json
{
  "mcpServers": {
    "sheetgate-sales": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://sheetgate.yourdomain.com/mcp/sales"
      ]
    }
  }
}
```

### Cursor AI Setup
In Cursor, go to **Settings > Features > MCP**, click **Add New MCP Server**:
- **Name**: `sheetgate-sales`
- **Type**: `http`
- **URL**: `https://sheetgate.yourdomain.com/mcp/sales`

### Available MCP Tools
1. `list_tabs`: Lists all worksheets/tabs available in the Google Sheet.
2. `get_schema`: Returns column headers, detected data types, row count, and top 3 sample rows.
3. `query_rows`: Queries rows with support for filters, search, sorting, limit/offset, fields, and aggregates.

---

## Configuration (`config.php`)

| Key | Environment Variable | Default | Description |
|---|---|---|---|
| `app_name` | `SHEETGATE_APP_NAME` | `SheetGate` | Display name of the application |
| `app_key` | `SHEETGATE_APP_KEY` | Auto-generated | 32-byte master encryption key |
| `default_ttl` | `SHEETGATE_DEFAULT_TTL` | `300` | Default cache duration (seconds) |
| `min_ttl` | - | `30` | Minimum allowed TTL floor |
| `default_rate_limit` | `SHEETGATE_RATE_LIMIT` | `60` | Requests allowed per minute per IP |
| `google.client_id` | `GOOGLE_CLIENT_ID` | `""` | Google Cloud OAuth Client ID |
| `google.client_secret` | `GOOGLE_CLIENT_SECRET` | `""` | Google Cloud OAuth Client Secret |

## Security

For vulnerability disclosures and details on our defense-in-depth architecture (SSRF validation, Libsodium encryption, SHA-256 API key hashing, prepared statements), please see our [Security Policy](SECURITY.md).

---

## License

SheetGate is open source software licensed under the [MIT License](LICENSE).

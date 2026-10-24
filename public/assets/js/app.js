/**
 * SheetGate - Admin Dashboard JavaScript Application
 * Vanilla JS, no build steps required.
 */

const app = {
  csrfToken: null,
  endpoints: [],
  currentView: 'endpoints',
  authStatus: null,

  async init() {
    this.checkOAuthParams();
    await this.checkAuth();
  },

  checkOAuthParams() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('oauth_success')) {
      this.toast('Google Account connected successfully!', 'success');
      window.history.replaceState({}, document.title, window.location.pathname);
    } else if (urlParams.get('oauth_error')) {
      this.toast('Google OAuth failed: ' + urlParams.get('oauth_error'), 'danger');
      window.history.replaceState({}, document.title, window.location.pathname);
    }
  },

  async checkAuth() {
    try {
      const res = await fetch('/admin/api/auth/status');
      const data = await res.json();
      this.authStatus = data;
      this.csrfToken = data.csrf_token;

      if (!data.configured) {
        this.openSetupModal();
      } else if (!data.logged_in) {
        this.openLoginModal();
      } else {
        document.getElementById('logoutBtn').style.display = 'inline-flex';
        await this.loadEndpoints();
        await this.logsView.loadStats();
      }
    } catch (e) {
      this.toast('Failed to initialize session: ' + e.message, 'danger');
    }
  },

  async handleAuthSubmit(e) {
    e.preventDefault();
    const pass = document.getElementById('authPasswordInput').value;
    const isSetup = !this.authStatus?.configured;
    const url = isSetup ? '/admin/api/auth/setup' : '/admin/api/auth/login';

    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': this.csrfToken,
        },
        body: JSON.stringify({ password: pass }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Authentication failed');

      this.closeModal('authModal');
      this.csrfToken = data.csrf_token;
      document.getElementById('logoutBtn').style.display = 'inline-flex';
      this.toast(isSetup ? 'Admin password setup complete!' : 'Logged in successfully', 'success');
      await this.loadEndpoints();
    } catch (err) {
      this.toast(err.message, 'danger');
    }
  },

  openSetupModal() {
    document.getElementById('authModalTitle').innerText = 'Set Admin Password';
    document.getElementById('authModalDesc').innerText = 'Create a secure master password to access your SheetGate admin dashboard.';
    document.getElementById('authSubmitBtn').innerText = 'Save Password & Continue';
    this.openModal('authModal');
  },

  openLoginModal() {
    document.getElementById('authModalTitle').innerText = 'SheetGate Login';
    document.getElementById('authModalDesc').innerText = 'Enter your admin password to access the dashboard.';
    document.getElementById('authSubmitBtn').innerText = 'Login';
    this.openModal('authModal');
  },

  async logout() {
    await fetch('/admin/api/auth/logout', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': this.csrfToken }
    });
    window.location.reload();
  },

  navigate(viewName) {
    this.currentView = viewName;
    document.querySelectorAll('.view-section').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));

    const activeView = document.getElementById(`view-${viewName}`);
    if (activeView) activeView.style.display = 'block';

    const activeNav = document.querySelector(`.nav-item[data-view="${viewName}"]`);
    if (activeNav) activeNav.classList.add('active');

    if (viewName === 'playground') this.playground.init();
    if (viewName === 'mcp') this.mcpView.init();
    if (viewName === 'drift') this.driftView.load();
    if (viewName === 'logs') this.logsView.load();
    if (viewName === 'settings') this.settingsView.load();
  },

  async loadEndpoints() {
    try {
      const res = await fetch('/admin/api/endpoints');
      const data = await res.json();
      this.endpoints = data.endpoints || [];
      this.renderEndpoints();
      this.updateGlobalStats();
    } catch (e) {
      this.toast('Failed to load endpoints: ' + e.message, 'danger');
    }
  },

  renderEndpoints() {
    const list = document.getElementById('endpointsList');
    if (!this.endpoints.length) {
      list.innerHTML = `
        <div style="grid-column: 1 / -1; text-align: center; padding: 4rem 1rem; background: var(--bg-surface); border: 1px dashed var(--border-strong); border-radius: var(--radius-lg);">
          <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📄</div>
          <h3 style="font-weight: 700; font-size: 1.25rem;">No Google Sheets connected yet</h3>
          <p style="color: var(--text-secondary); font-size: 0.85rem; max-width: 450px; margin: 0.5rem auto 1.5rem;">
            Turn any public Google Sheet or private restricted sheet into a fast cached JSON API and MCP server in seconds.
          </p>
          <button class="btn btn-primary" onclick="app.openCreateModal()">+ Connect Your First Sheet</button>
        </div>
      `;
      return;
    }

    const baseUrl = window.location.origin;

    list.innerHTML = this.endpoints.map(ep => {
      const apiUrl = `${baseUrl}/api/${ep.id}`;
      const isPublic = ep.visibility === 'public';
      const isOk = ep.last_status === 'ok';
      const driftAlert = (parseInt(ep.drift_count) || 0) > 0;

      return `
        <div class="endpoint-card">
          <div class="endpoint-top">
            <div>
              <div class="endpoint-name">
                <span>${this.escape(ep.name)}</span>
                ${driftAlert ? '<span class="badge badge-drift">DRIFT ALERT</span>' : ''}
              </div>
              <div style="display: flex; gap: 0.4rem; align-items: center; margin-top: 0.35rem;">
                <span class="badge ${isPublic ? 'badge-public' : 'badge-protected'}">
                  ${ep.visibility}
                </span>
                <span class="badge ${isOk ? 'badge-status-ok' : 'badge-status-error'}">
                  ${ep.last_status || 'ok'}
                </span>
                ${ep.schema_locked ? '<span class="badge" style="background: rgba(99, 102, 241, 0.15); color: #818cf8;">LOCKED</span>' : ''}
              </div>
            </div>
            <button class="btn btn-secondary btn-icon" title="Delete Endpoint" onclick="app.deleteEndpoint('${ep.id}')">
              🗑️
            </button>
          </div>

          <p class="endpoint-desc">${this.escape(ep.description || 'No description provided.')}</p>

          <div class="endpoint-meta">
            <div class="meta-item">
              <span class="meta-key">Rows Cached</span>
              <span class="meta-val">${parseInt(ep.row_count || 0).toLocaleString()}</span>
            </div>
            <div class="meta-item">
              <span class="meta-key">Cache TTL</span>
              <span class="meta-val">${ep.cache_ttl}s</span>
            </div>
            <div class="meta-item">
              <span class="meta-key">Rate Limit</span>
              <span class="meta-val">${ep.rate_limit_per_min ? ep.rate_limit_per_min + '/min' : 'Unlimited'}</span>
            </div>
            <div class="meta-item">
              <span class="meta-key">API Keys</span>
              <span class="meta-val">${ep.keys_count || 0} active</span>
            </div>
          </div>

          <div class="endpoint-url-box">
            <span class="endpoint-url-text">${apiUrl}</span>
            <button class="btn btn-secondary btn-sm" onclick="app.copyToClipboard('${apiUrl}')">Copy</button>
          </div>

          <div class="endpoint-actions">
            <div style="display: flex; gap: 0.4rem;">
              <button class="btn btn-primary btn-sm" onclick="app.openPlaygroundFor('${ep.id}')">
                <span>⚡</span> Query
              </button>
              <button class="btn btn-secondary btn-sm" onclick="app.refreshEndpoint('${ep.id}', this)">
                <span>🔄</span> Refresh
              </button>
            </div>
            <button class="btn btn-secondary btn-sm" onclick="app.openKeysModal('${ep.id}')">
              <span>🔑</span> Keys
            </button>
          </div>
        </div>
      `;
    }).join('');
  },

  updateGlobalStats() {
    const totalRows = this.endpoints.reduce((sum, ep) => sum + (parseInt(ep.row_count) || 0), 0);
    const totalDrifts = this.endpoints.reduce((sum, ep) => sum + (parseInt(ep.drift_count) || 0), 0);

    document.getElementById('statEndpointCount').innerText = this.endpoints.length;
    document.getElementById('statRowCount').innerText = totalRows.toLocaleString();
    document.getElementById('statDriftCount').innerText = totalDrifts;
  },

  openPlaygroundFor(id) {
    this.navigate('playground');
    const select = document.getElementById('playgroundEndpointSelect');
    if (select) {
      select.value = id;
      this.playground.onEndpointChange();
    }
  },

  openCreateModal() {
    document.getElementById('createEndpointForm').reset();
    document.getElementById('testPreviewBox').style.display = 'none';
    this.openModal('createModal');
  },

  toggleSourceType(type) {
    const isPublic = type === 'public_url';
    document.getElementById('publicUrlGroup').style.display = isPublic ? 'block' : 'none';
    document.getElementById('oauthSheetGroup').style.display = isPublic ? 'none' : 'block';
  },

  async testSheetUrl() {
    const url = document.getElementById('epSheetUrl').value.trim();
    if (!url) {
      this.toast('Please enter a Google Sheets URL first', 'warning');
      return;
    }

    const previewBox = document.getElementById('testPreviewBox');
    previewBox.style.display = 'block';
    previewBox.innerHTML = '<span style="color: var(--text-muted); font-size: 0.8rem;">Testing Google Sheet access and reading CSV headers...</span>';

    try {
      const res = await fetch('/admin/api/endpoints/test', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': this.csrfToken,
        },
        body: JSON.stringify({ sheet_url: url }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to read sheet');

      // Autofill name if empty
      const nameInput = document.getElementById('epName');
      if (!nameInput.value) {
        nameInput.value = 'Sheet ' + data.spreadsheet_id.substring(0, 6);
      }

      const chips = data.columns.map(c => `
        <span style="font-size: 0.7rem; font-family: var(--font-mono); background: var(--bg-surface-active); padding: 0.2rem 0.5rem; border-radius: var(--radius-sm); border: 1px solid var(--border-subtle);">
          ${this.escape(c.name)} <span style="color: var(--text-muted);">(${c.type})</span>
        </span>
      `).join('');

      previewBox.innerHTML = `
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
          <span style="color: var(--success); font-weight: 700; font-size: 0.8rem;">✓ Valid Sheet: ${data.row_count} rows found</span>
          <span style="font-size: 0.75rem; color: var(--text-secondary);">${data.columns.length} columns</span>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 0.35rem;">${chips}</div>
      `;
      this.toast(`Sheet verified! Found ${data.row_count} rows across ${data.columns.length} columns.`, 'success');
    } catch (e) {
      previewBox.innerHTML = `<span style="color: var(--danger); font-size: 0.8rem;">Error: ${this.escape(e.message)}</span>`;
      this.toast(e.message, 'danger');
    }
  },

  async submitCreateEndpoint(e) {
    e.preventDefault();
    const sourceType = document.querySelector('input[name="source_type"]:checked').value;

    const payload = {
      name: document.getElementById('epName').value.trim(),
      id: document.getElementById('epId').value.trim(),
      description: document.getElementById('epDescription').value.trim(),
      source_type: sourceType,
      sheet_url: document.getElementById('epSheetUrl').value.trim(),
      spreadsheet_id: document.getElementById('epSpreadsheetId')?.value.trim() || '',
      sheet_name: document.getElementById('epSheetName')?.value.trim() || '',
      cache_ttl: parseInt(document.getElementById('epTtl').value) || 300,
      visibility: document.getElementById('epVisibility').value,
      rate_limit_per_min: parseInt(document.getElementById('epRateLimit').value) || 60,
      cors_origins: document.getElementById('epCors').value.trim() || '*',
      schema_locked: document.getElementById('epSchemaLocked').checked ? 1 : 0,
      webhook_url: document.getElementById('epWebhook').value.trim(),
    };

    try {
      const res = await fetch('/admin/api/endpoints', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': this.csrfToken,
        },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to create endpoint');

      this.closeModal('createModal');
      this.toast(`Endpoint '${payload.name}' created successfully!`, 'success');
      await this.loadEndpoints();
    } catch (err) {
      this.toast(err.message, 'danger');
    }
  },

  async refreshEndpoint(id, btn) {
    if (btn) btn.innerHTML = '<span>⏳</span> Refreshing...';
    try {
      const res = await fetch(`/api/${id}/refresh`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': this.csrfToken }
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Refresh failed');

      this.toast(`Cache refreshed: ${data.row_count} rows loaded`, 'success');
      await this.loadEndpoints();
    } catch (e) {
      this.toast('Refresh failed: ' + e.message, 'danger');
    } finally {
      if (btn) btn.innerHTML = '<span>🔄</span> Refresh';
    }
  },

  async deleteEndpoint(id) {
    if (!confirm(`Are you sure you want to delete endpoint '${id}'? This action cannot be undone.`)) return;

    try {
      const res = await fetch(`/admin/api/endpoints/${id}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': this.csrfToken }
      });
      if (!res.ok) throw new Error('Failed to delete endpoint');

      this.toast('Endpoint deleted', 'success');
      await this.loadEndpoints();
    } catch (e) {
      this.toast(e.message, 'danger');
    }
  },

  async openKeysModal(endpointId) {
    const keyName = prompt('Enter a label for the new API key (e.g. Production Key, Claude AI):');
    if (!keyName) return;

    try {
      const res = await fetch(`/admin/api/endpoints/${endpointId}/keys`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': this.csrfToken,
        },
        body: JSON.stringify({ name: keyName }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to generate key');

      document.getElementById('newApiKeyText').innerText = data.key.plain_key;
      this.openModal('apiKeyModal');
      await this.loadEndpoints();
    } catch (e) {
      this.toast(e.message, 'danger');
    }
  },

  openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('open');
  },

  closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
  },

  toast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    let icon = 'ℹ️';
    if (type === 'success') icon = '✓';
    if (type === 'danger') icon = '⚠️';
    if (type === 'warning') icon = '⚡';

    toast.innerHTML = `<span style="font-weight: 700;">${icon}</span> <span>${this.escape(message)}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transition = 'opacity 0.3s ease';
      setTimeout(() => toast.remove(), 300);
    }, 4000);
  },

  copyToClipboard(text) {
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
      this.toast('Copied to clipboard!', 'success');
    }).catch(() => {
      this.toast('Failed to copy', 'danger');
    });
  },

  escape(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[m]));
  }
};

/**
 * Playground Controller
 */
app.playground = {
  activeTab: 'response',

  init() {
    this.populateEndpointDropdown();
    this.onEndpointChange();
  },

  populateEndpointDropdown() {
    const select = document.getElementById('playgroundEndpointSelect');
    const cur = select.value;
    select.innerHTML = '<option value="">Select an endpoint...</option>' +
      app.endpoints.map(ep => `<option value="${ep.id}">${app.escape(ep.name)} (${ep.id})</option>`).join('');
    if (cur) select.value = cur;
    else if (app.endpoints.length > 0) select.value = app.endpoints[0].id;
  },

  onEndpointChange() {
    const epId = document.getElementById('playgroundEndpointSelect').value;
    const ep = app.endpoints.find(e => e.id === epId);

    const apiKeyGroup = document.getElementById('pgApiKeyGroup');
    if (ep && ep.visibility === 'protected') {
      apiKeyGroup.style.display = 'block';
    } else {
      apiKeyGroup.style.display = 'none';
    }

    this.updateQueryUrl();
  },

  addFilterRow() {
    const container = document.getElementById('pgFiltersContainer');
    const row = document.createElement('div');
    row.className = 'pg-filter-row';
    row.style = 'display: grid; grid-template-columns: 1fr 100px 1.2fr 30px; gap: 0.35rem; align-items: center;';

    row.innerHTML = `
      <input type="text" class="form-input filter-col" placeholder="column_name" style="padding: 0.4rem;">
      <select class="form-select filter-op" style="padding: 0.4rem; font-size: 0.75rem;">
        <option value="eq">eq (=)</option>
        <option value="neq">neq (!=)</option>
        <option value="contains">contains</option>
        <option value="gt">gt (&gt;)</option>
        <option value="gte">gte (&gt;=)</option>
        <option value="lt">lt (&lt;)</option>
        <option value="lte">lte (&lt;=)</option>
        <option value="in">in (list)</option>
        <option value="starts_with">starts with</option>
      </select>
      <input type="text" class="form-input filter-val" placeholder="value" style="padding: 0.4rem;">
      <button type="button" class="btn btn-secondary btn-sm" onclick="this.parentElement.remove()" style="padding: 0.35rem 0.5rem;">✕</button>
    `;
    container.appendChild(row);
  },

  buildQueryParams() {
    const params = new URLSearchParams();

    const search = document.getElementById('pgSearch').value.trim();
    if (search) params.set('search', search);

    const sort = document.getElementById('pgSort').value.trim();
    if (sort) {
      params.set('sort', sort);
      params.set('order', document.getElementById('pgOrder').value);
    }

    const limit = document.getElementById('pgLimit').value;
    if (limit && limit !== '25') params.set('limit', limit);

    const offset = document.getElementById('pgOffset').value;
    if (offset && offset !== '0') params.set('offset', offset);

    const fields = document.getElementById('pgFields').value.trim();
    if (fields) params.set('fields', fields);

    const aggregates = document.getElementById('pgAggregates').value.trim();
    if (aggregates) params.set('aggregate', aggregates);

    const groupBy = document.getElementById('pgGroupBy').value.trim();
    if (groupBy) params.set('group_by', groupBy);

    // Filters
    const filterRows = document.querySelectorAll('.pg-filter-row');
    filterRows.forEach(row => {
      const col = row.querySelector('.filter-col').value.trim();
      const op = row.querySelector('.filter-op').value;
      const val = row.querySelector('.filter-val').value.trim();
      if (col && val) {
        if (op === 'eq') params.set(col, val);
        else params.set(col, `${op}.${val}`);
      }
    });

    return params;
  },

  updateQueryUrl() {
    const epId = document.getElementById('playgroundEndpointSelect').value;
    const urlBox = document.getElementById('pgActiveUrl');
    if (!epId) {
      urlBox.innerText = 'Select an endpoint to start';
      return;
    }

    const params = this.buildQueryParams();
    const qs = params.toString() ? '?' + params.toString() : '';
    const fullUrl = `${window.location.origin}/api/${epId}${qs}`;
    urlBox.innerText = fullUrl;

    this.generateSnippets(fullUrl, epId);
  },

  generateSnippets(url, epId) {
    const ep = app.endpoints.find(e => e.id === epId);
    const apiKey = document.getElementById('pgApiKey')?.value.trim() || 'YOUR_API_KEY';
    const authHeader = (ep && ep.visibility === 'protected') ? ` -H "Authorization: Bearer ${apiKey}"` : '';

    // cURL
    document.getElementById('pgCurlOutput').innerText = `curl -X GET "${url}"${authHeader}`;

    // JS Fetch
    const jsHeaders = (ep && ep.visibility === 'protected') ? `,
  headers: { "Authorization": "Bearer ${apiKey}" }` : '';
    document.getElementById('pgJsOutput').innerText = `const response = await fetch("${url}"${jsHeaders});
const result = await response.json();
console.log(result.data);`;

    // Python requests
    const pyHeaders = (ep && ep.visibility === 'protected') ? `,
    headers={"Authorization": f"Bearer ${apiKey}"}` : '';
    document.getElementById('pgPythonOutput').innerText = `import requests

url = "${url}"
response = requests.get(url${pyHeaders})
data = response.json()
print(f"Total: {data.get('total')}")
print(data.get('data'))`;
  },

  async runQuery() {
    const epId = document.getElementById('playgroundEndpointSelect').value;
    if (!epId) {
      app.toast('Please select an endpoint first', 'warning');
      return;
    }

    this.updateQueryUrl();
    const url = document.getElementById('pgActiveUrl').innerText;
    const statusEl = document.getElementById('pgResponseStatus');
    const outputEl = document.getElementById('pgJsonOutput');

    statusEl.innerText = 'Status: Fetching...';
    outputEl.innerText = '// Requesting data...';

    const ep = app.endpoints.find(e => e.id === epId);
    const headers = {};
    if (ep && ep.visibility === 'protected') {
      const key = document.getElementById('pgApiKey').value.trim();
      if (key) headers['Authorization'] = `Bearer ${key}`;
    }

    const t0 = performance.now();
    try {
      const res = await fetch(url, { headers });
      const duration = Math.round(performance.now() - t0);
      const cacheStatus = res.headers.get('X-Cache') || 'UNKNOWN';
      const data = await res.json();

      statusEl.innerHTML = `HTTP ${res.status} · Cache: <span style="color: var(--primary); font-weight:700;">${cacheStatus}</span> · Latency: ${duration}ms`;
      outputEl.innerText = JSON.stringify(data, null, 2);

      // Render Table View
      if (data.data && Array.isArray(data.data) && data.data.length > 0) {
        this.renderTable(data.data);
      } else if (data.groups && Array.isArray(data.groups)) {
        this.renderTable(data.groups);
      } else {
        document.getElementById('pgDataTable').innerHTML = '<thead><tr><th>No rows match your query</th></tr></thead>';
      }
    } catch (e) {
      statusEl.innerText = 'Status: Error';
      outputEl.innerText = `// Error: ${e.message}`;
      app.toast(e.message, 'danger');
    }
  },

  renderTable(rows) {
    const table = document.getElementById('pgDataTable');
    const cols = Object.keys(rows[0]);

    const thead = '<thead><tr>' + cols.map(c => `<th>${app.escape(c)}</th>`).join('') + '</tr></thead>';
    const tbody = '<tbody>' + rows.map(r => {
      return '<tr>' + cols.map(c => {
        let val = r[c];
        if (typeof val === 'object' && val !== null) val = JSON.stringify(val);
        return `<td>${app.escape(val ?? 'null')}</td>`;
      }).join('') + '</tr>';
    }).join('') + '</tbody>';

    table.innerHTML = thead + tbody;
  },

  switchTab(tabName, btn) {
    this.activeTab = tabName;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');

    const tabs = ['response', 'table', 'curl', 'js', 'python'];
    tabs.forEach(t => {
      const el = document.getElementById(`pgTab${t.charAt(0).toUpperCase() + t.slice(1)}`);
      if (el) el.style.display = (t === tabName) ? 'block' : 'none';
    });
  }
};

/**
 * MCP Server View Controller
 */
app.mcpView = {
  init() {
    const select = document.getElementById('mcpEndpointSelect');
    select.innerHTML = '<option value="">Select an endpoint...</option>' +
      app.endpoints.map(ep => `<option value="${ep.id}">${app.escape(ep.name)} (${ep.id})</option>`).join('');
    if (app.endpoints.length > 0) {
      select.value = app.endpoints[0].id;
    }
    this.onEndpointChange();
  },

  onEndpointChange() {
    const epId = document.getElementById('mcpEndpointSelect').value;
    if (!epId) return;

    const mcpUrl = `${window.location.origin}/mcp/${epId}`;

    // Claude Desktop snippet
    const claudeConfig = {
      mcpServers: {
        [`sheetgate-${epId}`]: {
          command: "npx",
          args: ["-y", "mcp-remote", mcpUrl]
        }
      }
    };
    document.getElementById('mcpClaudeCode').innerText = JSON.stringify(claudeConfig, null, 2);

    // Cursor MCP config
    const cursorConfig = {
      name: `sheetgate-${epId}`,
      url: mcpUrl,
      type: "http"
    };
    document.getElementById('mcpCursorCode').innerText = JSON.stringify(cursorConfig, null, 2);
  }
};

/**
 * Schema Drift View Controller
 */
app.driftView = {
  async load() {
    const tbody = document.getElementById('driftLogsBody');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--text-muted);">Inspecting schema snapshots...</td></tr>';

    let allDrifts = [];
    for (const ep of app.endpoints) {
      try {
        const res = await fetch(`/admin/api/endpoints/${ep.id}`);
        const data = await res.json();
        if (data.drift_logs && data.drift_logs.length) {
          allDrifts.push(...data.drift_logs.map(d => ({ ...d, endpoint_name: ep.name })));
        }
      } catch (e) {}
    }

    if (!allDrifts.length) {
      tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No schema drift detected. All endpoints match their baseline column definitions.</td></tr>';
      return;
    }

    tbody.innerHTML = allDrifts.map(d => {
      const details = d.details || {};
      const added = (details.added_columns || []).join(', ');
      const dropped = (details.dropped_columns || []).join(', ');
      const desc = [];
      if (added) desc.push(`<span style="color: var(--success);">Added: ${app.escape(added)}</span>`);
      if (dropped) desc.push(`<span style="color: var(--danger);">Dropped: ${app.escape(dropped)}</span>`);

      return `
        <tr>
          <td>${new Date(d.created_at * 1000).toLocaleString()}</td>
          <td style="font-weight: 600;">${app.escape(d.endpoint_name)}</td>
          <td><span class="badge badge-drift">${d.event_type}</span></td>
          <td>${desc.join(' · ') || 'Schema structure shift'}</td>
          <td>
            <button class="btn btn-secondary btn-sm" onclick="app.driftView.acknowledge('${d.endpoint_id}')">Acknowledge</button>
          </td>
        </tr>
      `;
    }).join('');
  },

  async acknowledge(endpointId) {
    try {
      const res = await fetch(`/admin/api/endpoints/${endpointId}/drift/acknowledge`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': app.csrfToken }
      });
      if (!res.ok) throw new Error('Failed to acknowledge schema');
      app.toast('Schema drift acknowledged and updated to new baseline', 'success');
      await this.load();
      await app.loadEndpoints();
    } catch (e) {
      app.toast(e.message, 'danger');
    }
  }
};

/**
 * Request Logs View Controller
 */
app.logsView = {
  async load() {
    await this.refresh();
  },

  async refresh() {
    const tbody = document.getElementById('requestLogsBody');
    try {
      const res = await fetch('/admin/api/logs?limit=50');
      const data = await res.json();
      const logs = data.logs || [];

      if (!logs.length) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No request traffic yet. Query an endpoint to see live logs.</td></tr>';
        return;
      }

      tbody.innerHTML = logs.map(l => {
        const is200 = l.status_code >= 200 && l.status_code < 300;
        let cacheBadge = `<span class="badge badge-status-ok">${l.cache_status}</span>`;
        if (l.cache_status === 'STALE') cacheBadge = `<span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #fbbf24;">STALE</span>`;
        if (l.cache_status === 'MISS') cacheBadge = `<span class="badge" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa;">MISS</span>`;

        return `
          <tr>
            <td>${new Date(l.created_at * 1000).toLocaleTimeString()}</td>
            <td style="font-weight: 600; color: #93c5fd;">${app.escape(l.endpoint_id)}</td>
            <td><code>${l.method}</code></td>
            <td><span class="badge ${is200 ? 'badge-status-ok' : 'badge-status-error'}">${l.status_code}</span></td>
            <td>${cacheBadge}</td>
            <td>${l.response_time_ms}ms</td>
            <td>${app.escape(l.ip_address)}</td>
          </tr>
        `;
      }).join('');
    } catch (e) {
      app.toast('Failed to load logs: ' + e.message, 'danger');
    }
  },

  async loadStats() {
    try {
      const res = await fetch('/admin/api/logs?limit=1');
      const data = await res.json();
      if (data.stats) {
        document.getElementById('statHitRatio').innerText = data.stats.cache_hit_ratio + '%';
      }
    } catch (e) {}
  }
};

/**
 * Settings View Controller
 */
app.settingsView = {
  async load() {
    const baseUrl = window.location.origin;
    document.getElementById('cronUrlText').innerText = `${baseUrl}/api/cron`;

    try {
      const res = await fetch('/admin/api/oauth/config');
      const data = await res.json();
      document.getElementById('gClientId').value = data.client_id || '';
      document.getElementById('gRedirectUri').innerText = data.redirect_uri || `${baseUrl}/oauth/callback`;
      document.getElementById('gApiKey').value = data.api_key || '';
      if (data.client_secret_configured) {
        document.getElementById('gClientSecret').placeholder = data.masked_secret;
      }
      await this.loadConnectedAccounts();
    } catch (e) {}
  },

  async saveOauthConfig(e) {
    e.preventDefault();
    const payload = {
      client_id: document.getElementById('gClientId').value.trim(),
      api_key: document.getElementById('gApiKey').value.trim(),
    };
    const secret = document.getElementById('gClientSecret').value.trim();
    if (secret) payload.client_secret = secret;

    try {
      const res = await fetch('/admin/api/oauth/config', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': app.csrfToken,
        },
        body: JSON.stringify(payload),
      });
      if (!res.ok) throw new Error('Failed to save OAuth credentials');
      app.toast('Google OAuth configuration saved', 'success');
    } catch (e) {
      app.toast(e.message, 'danger');
    }
  },

  async startGoogleOAuth() {
    try {
      const res = await fetch('/admin/api/oauth/auth-url');
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to generate OAuth URL');
      window.location.href = data.auth_url;
    } catch (e) {
      app.toast(e.message, 'danger');
    }
  },

  async loadConnectedAccounts() {
    const list = document.getElementById('connectedAccountsList');
    try {
      const res = await fetch('/admin/api/oauth/accounts');
      const data = await res.json();
      const accounts = data.accounts || [];

      if (!accounts.length) {
        list.innerHTML = '<div style="font-size: 0.8rem; color: var(--text-muted);">No Google accounts connected yet.</div>';
        return;
      }

      list.innerHTML = accounts.map(a => `
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.65rem 0.85rem; background: var(--bg-surface-elevated); border-radius: var(--radius-md); margin-top: 0.5rem;">
          <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span>🟢</span>
            <span style="font-weight: 600; font-size: 0.85rem;">${app.escape(a.account_email)}</span>
          </div>
          <button class="btn btn-danger btn-sm" onclick="app.settingsView.disconnectAccount(${a.id})">Disconnect</button>
        </div>
      `).join('');
    } catch (e) {}
  },

  async disconnectAccount(id) {
    if (!confirm('Disconnect this Google account?')) return;
    try {
      const res = await fetch(`/admin/api/oauth/accounts/${id}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': app.csrfToken }
      });
      if (!res.ok) throw new Error('Failed to disconnect');
      app.toast('Google account disconnected', 'success');
      await this.loadConnectedAccounts();
    } catch (e) {
      app.toast(e.message, 'danger');
    }
  },

  async updatePassword(e) {
    e.preventDefault();
    const p1 = document.getElementById('newAdminPassword').value;
    const p2 = document.getElementById('confirmAdminPassword').value;
    if (p1 !== p2) {
      app.toast('Passwords do not match', 'warning');
      return;
    }

    try {
      const res = await fetch('/admin/api/auth/setup', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': app.csrfToken,
        },
        body: JSON.stringify({ password: p1 }),
      });
      if (!res.ok) throw new Error('Failed to update password');
      app.toast('Admin password updated successfully', 'success');
      e.target.reset();
    } catch (e) {
      app.toast(e.message, 'danger');
    }
  }
};

// Bootstrap application on DOM ready
document.addEventListener('DOMContentLoaded', () => {
  app.init();
});

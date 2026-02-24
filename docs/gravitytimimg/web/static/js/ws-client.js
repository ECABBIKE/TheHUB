/**
 * ws-client.js — Shared WebSocket client for GravityTiming.
 *
 * Auto-reconnect, channel subscribe, message dispatch.
 * Used by all browser clients (admin, finish, speaker, overlay, start, standings).
 *
 * Usage:
 *   const ws = new GravityWS(['finish']);
 *   ws.on('punch', (msg) => { ... });
 *   ws.on('standings', (msg) => { ... });
 *   ws.on('highlight', (msg) => { ... });
 */

class GravityWS {
    constructor(channels = ['all']) {
        this.channels = channels;
        this.handlers = {};
        this.ws = null;
        this._reconnectDelay = 2000;
        this._maxDelay = 30000;
        this._currentDelay = this._reconnectDelay;
        this._statusEl = null;
        this.connect();
    }

    /**
     * Connect to WebSocket server.
     */
    connect() {
        try {
            const protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
            const url = `${protocol}//${location.host}/ws`;

            this.ws = new WebSocket(url);

            this.ws.onopen = () => {
                console.log('[WS] Connected');
                this._currentDelay = this._reconnectDelay;
                this._updateStatus('online');

                // Subscribe to channels
                this.ws.send(JSON.stringify({
                    type: 'subscribe',
                    channels: this.channels
                }));

                if (this.handlers['_connected']) {
                    this.handlers['_connected']();
                }
            };

            this.ws.onmessage = (event) => {
                try {
                    const msg = JSON.parse(event.data);
                    this._dispatch(msg);
                } catch (e) {
                    console.warn('[WS] Parse error:', e);
                }
            };

            this.ws.onclose = () => {
                console.log('[WS] Disconnected, reconnecting in', this._currentDelay, 'ms');
                this._updateStatus('offline');

                setTimeout(() => this.connect(), this._currentDelay);
                this._currentDelay = Math.min(this._currentDelay * 1.5, this._maxDelay);

                if (this.handlers['_disconnected']) {
                    this.handlers['_disconnected']();
                }
            };

            this.ws.onerror = (err) => {
                console.error('[WS] Error:', err);
                this._updateStatus('offline');
            };
        } catch (e) {
            console.warn('[WS] Connect failed:', e);
            this._updateStatus('offline');
            setTimeout(() => this.connect(), this._currentDelay);
            this._currentDelay = Math.min(this._currentDelay * 1.5, this._maxDelay);
        }
    }

    /**
     * Register a handler for a message type.
     * Special types: '_connected', '_disconnected'
     */
    on(type, handler) {
        this.handlers[type] = handler;
    }

    /**
     * Bind to a status indicator element (dot + text).
     */
    bindStatus(dotEl, textEl) {
        this._statusDot = dotEl;
        this._statusText = textEl;
    }

    _dispatch(msg) {
        const type = msg.type;
        if (this.handlers[type]) {
            this.handlers[type](msg);
        }
        // Also call 'all' handler if registered
        if (this.handlers['*']) {
            this.handlers['*'](msg);
        }
    }

    _updateStatus(status) {
        if (this._statusDot) {
            this._statusDot.className = `status-dot ${status}`;
        }
        if (this._statusText) {
            this._statusText.textContent = status === 'online' ? 'Ansluten' : 'Frånkopplad';
        }
    }
}

/* ─── API helper ───────────────────────────────────────────────── */

const API = {
    /**
     * Generic fetch wrapper with JSON parsing.
     */
    async request(method, path, body = null) {
        const opts = {
            method,
            headers: { 'Content-Type': 'application/json' },
        };
        if (body !== null) {
            opts.body = JSON.stringify(body);
        }
        const resp = await fetch(`/api${path}`, opts);
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({ detail: resp.statusText }));
            throw new Error(err.detail || resp.statusText);
        }
        return resp.json();
    },

    get(path)        { return this.request('GET', path); },
    post(path, body) { return this.request('POST', path, body); },
    put(path, body)  { return this.request('PUT', path, body); },
    del(path)        { return this.request('DELETE', path); },

    /**
     * Upload a file via multipart form data.
     */
    async upload(path, file) {
        const form = new FormData();
        form.append('file', file);
        const resp = await fetch(`/api${path}`, { method: 'POST', body: form });
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({ detail: resp.statusText }));
            throw new Error(err.detail || resp.statusText);
        }
        return resp.json();
    },
};


/* ─── Time formatting (client-side) ────────────────────────────── */

function formatElapsed(seconds, precision = 'seconds') {
    if (seconds == null) return '';
    const neg = seconds < 0;
    const s = Math.abs(seconds);
    const min = Math.floor(s / 60);
    const rem = s - min * 60;

    let text;
    if (precision === 'hundredths') {
        text = `${min}:${rem.toFixed(2).padStart(5, '0')}`;
    } else if (precision === 'tenths') {
        text = `${min}:${rem.toFixed(1).padStart(4, '0')}`;
    } else {
        text = `${min}:${Math.floor(rem).toString().padStart(2, '0')}`;
    }
    return neg ? `-${text}` : text;
}

function formatBehind(seconds, precision = 'seconds') {
    if (seconds == null || seconds === 0) return '';
    return `+${formatElapsed(seconds, precision)}`;
}

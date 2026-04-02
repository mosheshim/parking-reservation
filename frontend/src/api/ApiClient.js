import { API_BASE_URL } from '../config/env';

/**
 * Small wrapper around `fetch` for this app.
 *
 * - Uses `API_BASE_URL` by default.
 * - Adds `Authorization: Bearer <token>` automatically (unless `useAuth: false`).
 * - Parses JSON responses and throws a helpful Error on non-2xx responses.
 */
export default class ApiClient {
	constructor({ baseUrl } = {}) {
		this.baseUrl = baseUrl || API_BASE_URL;
	}

	getAuthHeader() {
		const raw = sessionStorage.getItem('parking_auth_token');
		if (!raw) return null;

		// If token is missing/invalid, behave as not authenticated.
		const parsed = JSON.parse(raw);
		if (!parsed || !parsed.token) {
			return null;
		}

		return `Bearer ${parsed.token}`;
	}

	async request(path, { method = 'GET', headers = {}, body, useAuth = true, acceptStatuses = [] } = {}) {
		const authHeader = useAuth ? this.getAuthHeader() : null;

		const mergedHeaders = {
			...headers,
			...(authHeader ? { Authorization: authHeader } : {})
		};

		const res = await fetch(`${this.baseUrl}${path}`, {
			method,
			headers: mergedHeaders,
			body
		});

		const data = await res.json().catch(() => ({}));

		// Normalize errors so callers can handle status + payload.
		// Some UI flows (like reservation conflicts) expect certain non-2xx responses and handle them inline.
		if (!res.ok && !acceptStatuses.includes(res.status)) {
			const error = new Error(data.message || 'Request failed');
			error.status = res.status;
			error.data = data;
			throw error;
		}

		if (!res.ok && acceptStatuses.includes(res.status)) {
			return { ...data, __httpStatus: res.status };
		}

		return data;
	}

	postJson(path, payload, { headers = {}, useAuth = true, acceptStatuses = [] } = {}) {
		// Convenience helper for typical JSON POST requests.
		return this.request(path, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				...headers
			},
			body: JSON.stringify(payload),
			useAuth,
			acceptStatuses
		});
	}
}

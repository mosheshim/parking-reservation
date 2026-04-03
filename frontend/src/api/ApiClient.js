import { API_BASE_URL } from '../config/env';
import { getJwtAuthHeader } from '../services/AuthStorage';

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

	async request(path, { method = 'GET', headers = {}, body, useAuth = true, acceptStatuses = [] } = {}) {
		const authHeader = useAuth ? getJwtAuthHeader() : null;

		const mergedHeaders = {
			...headers,
			...(authHeader ? { Authorization: authHeader } : {})
		};

		let res;
		try {
			res = await fetch(`${this.baseUrl}${path}`, {
				method,
				headers: mergedHeaders,
				body
			});
		} catch (e) {
			const error = new Error('Network request failed');
			error.status = 0;
			error.data = null;
			error.userMessage = 'Unable to reach the server. Check your connection and try again.';
			error.cause = e;
			throw error;
		}

		const data = await res.json().catch(() => ({}));

		// Normalize errors so callers can handle status + payload.
		// Some UI flows (like reservation conflicts) expect certain non-2xx responses and handle them inline.
		if (!res.ok && !acceptStatuses.includes(res.status)) {
			const isServerError = res.status >= 500;
			const error = new Error(data.message || (isServerError ? 'Server error' : 'Request failed'));
			error.status = res.status;
			error.data = data;
			if (isServerError) {
				error.userMessage = 'Something went wrong on our side. Please try again in a moment.';
			}
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

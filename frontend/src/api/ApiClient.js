import { API_BASE_URL } from '../config/appConfig';

export default class ApiClient {
	constructor({ baseUrl } = {}) {
		this.baseUrl = baseUrl || API_BASE_URL;
	}

	getAuthHeader() {
		const raw = sessionStorage.getItem('parking_auth_token');
		if (!raw) return null;

		const parsed = JSON.parse(raw);
		if (!parsed || !parsed.token) {
			return null;
		}

		return `Bearer ${parsed.token}`;
	}

	async request(path, { method = 'GET', headers = {}, body, useAuth = true } = {}) {
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

		if (!res.ok) {
			const error = new Error(data.message || 'Request failed');
			error.status = res.status;
			error.data = data;
			throw error;
		}

		return data;
	}

	postJson(path, payload, { headers = {}, useAuth = true } = {}) {
		return this.request(path, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				...headers
			},
			body: JSON.stringify(payload),
			useAuth
		});
	}
}

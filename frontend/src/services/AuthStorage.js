const STORAGE_KEY = 'parking_auth_token';

/**
 * Builds the HTTP Authorization header value for the stored JWT.
 * This exists to keep token retrieval/validation centralized for HTTP + realtime clients.
 */
export function getJwtAuthHeader() {
	const raw = sessionStorage.getItem(STORAGE_KEY);
	if (!raw) return null;

	try {
		const parsed = JSON.parse(raw);
		if (!parsed || typeof parsed !== 'object' || !parsed.token) return null;
		return `Bearer ${parsed.token}`;
	} catch {
		return null;
	}
}

/**
 * Returns the auth payload stored in session storage, or null if missing/invalid.
 * This exists so consumers can safely read user/token data without duplicating JSON parsing.
 */
export function getStoredAuthPayload() {
	const raw = sessionStorage.getItem(STORAGE_KEY);
	if (!raw) return null;

	try {
		const parsed = JSON.parse(raw);
		if (!parsed || typeof parsed !== 'object') return null;
		return parsed;
	} catch {
		return null;
	}
}

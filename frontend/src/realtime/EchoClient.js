import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import {
	API_BASE_URL,
	REVERB_APP_KEY,
	REVERB_HOST,
	REVERB_PORT,
	REVERB_SCHEME
} from '../config/env';

window.Pusher = Pusher;

/**
 * Return the Authorization header value for the stored JWT, or null if missing/invalid.
 */
function getJwtAuthHeader() {
	const raw = sessionStorage.getItem('parking_auth_token');
	if (!raw) return null;

	try {
		const parsed = JSON.parse(raw);
		if (!parsed || !parsed.token) return null;
		return `Bearer ${parsed.token}`;
	} catch {
		return null;
	}
}

/**
 * Create a configured Laravel Echo instance for Reverb.
 */
export function createEcho() {
	const authHeader = getJwtAuthHeader();
	if (!API_BASE_URL) {
		throw new Error('Missing API_BASE_URL');
	}

	const appKey = REVERB_APP_KEY;
	if (!appKey) {
		throw new Error('Missing REVERB_APP_KEY');
	}

	const scheme = REVERB_SCHEME ?? 'https';
	const forceTLS = scheme === 'https';
	const enabledTransports = forceTLS ? ['wss', 'ws'] : ['ws'];

	return new Echo({
		broadcaster: 'reverb',
		key: appKey,
		wsHost: REVERB_HOST,
		wsPort: REVERB_PORT,
		wssPort: REVERB_PORT,
		forceTLS,
		enabledTransports,
		authEndpoint: `${API_BASE_URL}/broadcasting/auth`,
		auth: {
			headers: {
				...(authHeader ? { Authorization: authHeader } : {})
			}
		}
	});
}

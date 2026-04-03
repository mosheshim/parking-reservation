import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { getJwtAuthHeader } from '../services/AuthStorage';
import {
	API_BASE_URL,
	REVERB_APP_KEY,
	REVERB_HOST,
	REVERB_PORT,
	REVERB_SCHEME
} from '../config/env';

window.Pusher = Pusher;

/**
 * Creates a small controller that schedules reconnect attempts using exponential backoff.
 */
export function createConnectionLifecycleController({ connect, baseDelayMs = 500, maxDelayMs = 5000, jitterRatio = 0.2 } = {}) {
	let reconnectTimer = null;
	let reconnectAttempt = 0;
	let connectionWasLost = false;

	/**
	 * Mark the connection as lost so subsequent reconnect attempts use jitter.
	 */
	function markConnectionLost() {
		connectionWasLost = true;
	}

	/**
	 * Cancel any scheduled reconnect attempt.
	 * This exists to prevent pending timers from firing after a component unmounts or a connection recovers.
	 */
	function cleanup() {
		if (!reconnectTimer) return;
		window.clearTimeout(reconnectTimer);
		reconnectTimer = null;
	}

	/**
	 * Reset internal reconnect state and cancel any scheduled reconnect attempt.
	 * This exists to stop retry loops when the connection becomes healthy again.
	 */
	function reset() {
		reconnectAttempt = 0;
		connectionWasLost = false;
		cleanup();
	}

	function schedule() {
		if (reconnectTimer) return;

		// Exponential backoff: 500ms, 1000ms, 2000ms, ... capped at `maxDelayMs`.
		// This reduces load on the server/network when the socket is unstable.
		let delayMs = Math.min(maxDelayMs, baseDelayMs * Math.pow(2, reconnectAttempt));

		// Add jitter only if the connection was actually lost.
		// This prevents a "thundering herd" where many tabs/clients reconnect at the exact same time.
		if (connectionWasLost && jitterRatio > 0) {
			const jitterMs = delayMs * jitterRatio;
			delayMs = delayMs - jitterMs + Math.random() * (jitterMs * 2);
		}

		reconnectAttempt += 1;

		// Schedule a single reconnect attempt. If we disconnect again, caller will schedule again.
		reconnectTimer = window.setTimeout(() => {
			reconnectTimer = null;
			if (typeof connect === 'function') connect();
		}, delayMs);
	}

	return { schedule, reset, cleanup, markConnectionLost };
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

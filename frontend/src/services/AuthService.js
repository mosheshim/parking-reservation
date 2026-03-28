const STORAGE_KEY = 'parking_auth_token';

const API_BASE_URL = (typeof import.meta !== 'undefined' && import.meta.env && import.meta.env.API_BASE_URL)
	? import.meta.env.API_BASE_URL
	: 'http://localhost:8000/api';

export default {
	login(username, password) {
		return fetch(`${API_BASE_URL}/login`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json'
			},
			body: JSON.stringify({
				email: username,
				password
			})
		}).then(async (res) => {
			const data = await res.json().catch(() => ({}));

			if (!res.ok) {
				throw { success: false, message: data.message || 'Login failed' };
			}

			if (!data.token) {
				throw { success: false, message: 'Login failed' };
			}

			sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
				token: data.token,
				user: data.user || null
			}));
			return { success: true, username: (data.user && (data.user.email || data.user.name)) || username };
		});
	},

	logout() {
		sessionStorage.removeItem(STORAGE_KEY);
	},

	isLoggedIn() {
		return !!sessionStorage.getItem(STORAGE_KEY);
	},

	getCurrentUser() {
		const raw = sessionStorage.getItem(STORAGE_KEY);
		if (!raw) return null;

		try {
			const parsed = JSON.parse(raw);
			if (parsed && typeof parsed === 'object') {
				const user = parsed.user;
				if (user && typeof user === 'object') {
					return user.name || user.email || null;
				}
			}

			return null;
		} catch (e) {
			return raw;
		}
	}
};
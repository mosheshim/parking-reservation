import ApiClient from '../api/ApiClient';

const STORAGE_KEY = 'parking_auth_token';

const apiClient = new ApiClient();

export default {
	login(username, password) {
		return apiClient.postJson('/login', { email: username, password }, { useAuth: false })
			.then((data) => {
				if (!data.token) {
					throw { success: false, message: 'Login failed' };
				}

				sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
					token: data.token,
					user: data.user || null
				}));

				return { success: true, username: (data.user && (data.user.email || data.user.name)) || username };
			})
			.catch((err) => {
				throw { success: false, message: err && err.message ? err.message : 'Login failed' };
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
			if (!parsed || typeof parsed !== 'object') throw new Error('Invalid auth payload');

			const user = parsed.user;
			if (!user || typeof user !== 'object' || (!user.name && !user.email)) throw new Error('Invalid user payload');

			return user.name || user.email;
		} catch (err) {
			console.error('Could not parse logged user', err);
			return null;
		}
	}
};
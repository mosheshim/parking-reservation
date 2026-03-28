const CREDENTIALS = {
	'test': 'test',
	'test1': 'test1',
	'test2': 'test2'
};

const STORAGE_KEY = 'parking_auth_token';

export default {
	login(username, password) {
		return new Promise((resolve, reject) => {
			setTimeout(() => {
				if (CREDENTIALS[username] && CREDENTIALS[username] === password) {
					sessionStorage.setItem(STORAGE_KEY, username);
					resolve({ success: true, username });
				} else {
					reject({ success: false, message: 'Invalid credentials' });
				}
			}, 300);
		});
	},

	logout() {
		sessionStorage.removeItem(STORAGE_KEY);
	},

	isLoggedIn() {
		return !!sessionStorage.getItem(STORAGE_KEY);
	},

	getCurrentUser() {
		return sessionStorage.getItem(STORAGE_KEY);
	}
};
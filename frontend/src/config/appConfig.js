export const API_BASE_URL = (typeof import.meta !== 'undefined' && import.meta.env && import.meta.env.API_BASE_URL)
	? import.meta.env.API_BASE_URL
	: 'http://localhost:8000/api';

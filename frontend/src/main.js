import './style.css';
import Router from './core/Router';
import LoginPage from './pages/LoginPage';
import SlotsPage from './pages/SlotsPage';
import AuthService from './services/AuthService';

const app = document.querySelector('#app');

const routes = {
	'/login': LoginPage,
	'/slots': SlotsPage
};

const router = new Router(routes, app);

if (AuthService.isLoggedIn()) {
	router.navigate('/slots');
} else {
	router.navigate('/login');
}
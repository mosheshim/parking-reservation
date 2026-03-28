import Component from '../core/Component';
import AuthService from '../services/AuthService';

export default class LoginPage extends Component {
	constructor(element, router) {
		super(element);
		this.router = router;
		this.error = '';
	}

	template() {
		return `
      <div class="login-wrapper animate-enter">
        <div class="login-container">
          <div class="brand-logo">
            <svg class="brand-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
            </svg>
            <h2>Welcome</h2>
            <p class="login-subtitle">Enter your credentials to access parking</p>
          </div>

          <form id="login-form">
            <div class="form-group">
              <label for="username">Username</label>
              <input type="text" id="username" placeholder="e.g. test" autocomplete="username" required>
            </div>
            <div class="form-group">
              <label for="password">Password</label>
              <input type="password" id="password" placeholder="••••••••" autocomplete="current-password" required>
            </div>
            
            <p class="error-msg">${this.error}</p>
            
            <button type="submit" id="submit-btn">
              <span class="btn-text">Sign In</span>
              <div class="loader"></div>
            </button>
          </form>
        </div>
      </div>
    `;
	}

	afterRender() {
		const form = this.element.querySelector('#login-form');
		const submitBtn = this.element.querySelector('#submit-btn');
		const errorEl = this.element.querySelector('.error-msg');

		this.element.querySelector('#username').focus();

		form.addEventListener('submit', async (e) => {
			e.preventDefault();

			const user = form.querySelector('#username').value;
			const pass = form.querySelector('#password').value;

			this.error = '';
			errorEl.textContent = '';
			submitBtn.classList.add('loading');
			submitBtn.disabled = true;

			try {
				await AuthService.login(user, pass);
				this.router.navigate('/slots');
			} catch (err) {
				this.error = err.message;
				errorEl.textContent = this.error;

				const container = this.element.querySelector('.login-container');
				container.classList.remove('error-shake');
				void container.offsetWidth;
				container.classList.add('error-shake');

			} finally {
				submitBtn.classList.remove('loading');
				submitBtn.disabled = false;
			}
		});
	}
}
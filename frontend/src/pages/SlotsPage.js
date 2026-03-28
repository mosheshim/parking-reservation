import Component from '../core/Component';
import AuthService from '../services/AuthService';
import { getNextDays } from '../services/DateHelper';

export default class SlotsPage extends Component {
	constructor(element, router) {
		super(element);
		this.router = router;
		this.user = AuthService.getCurrentUser();
		this.days = getNextDays(3);
	}

	template() {
		const options = this.days.map((day, index) =>
			`<option value="${day.value}" ${index === 0 ? 'selected' : ''}>
         ${day.label} (${day.displayDate})
       </option>`
		).join('');

		return `
      <div class="animate-enter">
        <header>
          <h1>
            <svg class="header-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
            </svg>
            SmartParking
          </h1>
          <div class="user-info">
            <span class="user-greeting">Hello, <b>${this.user}</b></span>
            <button id="logout-btn">Log out</button>
          </div>
        </header>

        <main>
          <div class="controls-card">
            <label for="date-select">Booking Date</label>
            <select id="date-select">
              ${options}
            </select>
          </div>

          <div id="parking-slots-view" class="slots-container">
             <div class="placeholder-msg">
                <p>Select a date above to view available slots</p>
                <small style="opacity: 0.6">(Implementation Area)</small>
             </div>
          </div>
        </main>
      </div>
    `;
	}

	afterRender() {
		this.element.querySelector('#logout-btn').addEventListener('click', () => {
			AuthService.logout();
			this.router.navigate('/login');
		});

		const select = this.element.querySelector('#date-select');
		select.addEventListener('change', (e) => {
			const event = new CustomEvent('parking-date-change', { detail: e.target.value });
			window.dispatchEvent(event);
		});
	}
}
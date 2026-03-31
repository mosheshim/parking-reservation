export default class Component {
	constructor(element) {
		this.element = element;
	}

	template() {
		return '';
	}

	afterRender() {}

	render() {
		const unmountAll = this.getVueUnmountAll();
		if (unmountAll) {
			unmountAll(this.element);
		}

		this.element.innerHTML = this.template();
		this.afterRender();

		const mountAll = this.getVueMountAll();
		if (mountAll) {
			mountAll(this.element);
		}
	}

	/**
	 * Returns the global function that mounts all Vue islands within a given DOM root.
	 * This indirection keeps the base component framework decoupled from Vue.
	 */
	getVueMountAll() {
		return window.__parkingVueMountAll;
	}

	/**
	 * Returns the global function that unmounts all Vue islands within a given DOM root.
	 * This is required because page navigation replaces innerHTML, which would otherwise leak Vue apps.
	 */
	getVueUnmountAll() {
		return window.__parkingVueUnmountAll;
	}
}
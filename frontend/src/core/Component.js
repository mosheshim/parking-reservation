export default class Component {
	constructor(element) {
		this.element = element;
	}

	template() {
		return '';
	}

	afterRender() {}

	render() {
		this.element.innerHTML = this.template();
		this.afterRender();
	}
}
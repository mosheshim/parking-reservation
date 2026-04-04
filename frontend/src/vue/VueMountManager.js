/**
 * Mounts Vue components into DOM nodes marked with `data-vue-component`.
 * This exists so pages can embed Vue islands by only rendering a placeholder element.
 *
 * Currently this doesn't support components with same names under different directories.
 */

import { createApp } from 'vue';
import { getVueComponent } from './VueComponentRegistry';

const VUE_APP_KEY = '__parkingVueApp';

/**
 * Unmounts any Vue apps previously mounted under the given root.
 * This is necessary because the app uses string templates and re-renders by replacing `innerHTML`.
 */
export function unmountAll(rootElement) {
	if (!rootElement) return;

	const vueMountElements = rootElement.querySelectorAll(`[data-vue-component]`);
	vueMountElements.forEach((mountElement) => {
		const existingApp = mountElement[VUE_APP_KEY];
		if (existingApp) {
			existingApp.unmount();
			mountElement[VUE_APP_KEY] = null;
		}
	});
}

/**
 * Scans for `data-vue-component` mount points and mounts the matching components.
 * Props can be provided via `data-vue-props` as JSON.
 */
export function mountAll(rootElement) {
	if (!rootElement) return;

	const vueMountElements = rootElement.querySelectorAll(`[data-vue-component]`);
	vueMountElements.forEach((mountElement) => {
		const componentName = mountElement.dataset.vueComponent;
		const componentDefinition = getVueComponent(componentName);
		if (!componentDefinition) {
			console.warn(`Vue component not found: ${componentName}`);
			return;
		}

		// Props are provided as JSON because HTML attributes are strings.
		// This keeps the mounting API generic while still allowing rich component configuration.
		const rawPropsJson = mountElement.dataset.vueProps;
		let componentProps = undefined;
		if (rawPropsJson) {
			try {
				componentProps = JSON.parse(rawPropsJson);
			} catch (err) {
				console.warn(`Invalid JSON in data-vue-props for ${componentName}`, err);
			}
		}

		// We store the Vue app instance on the mount element so we can unmount it
		// on subsequent renders/navigation and avoid leaking reactive effects.
		const existingApp = mountElement[VUE_APP_KEY];
		if (existingApp) {
			existingApp.unmount();
			mountElement[VUE_APP_KEY] = null;
		}

		const vueApp = createApp(componentDefinition, componentProps);
		mountElement[VUE_APP_KEY] = vueApp;
		vueApp.mount(mountElement);
	});
}

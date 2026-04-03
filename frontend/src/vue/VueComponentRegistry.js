/**
 * Provides a central lookup for Vue components that can be mounted from plain HTML.
 * This exists to allow incremental Vue adoption without wiring `createApp()` in every page.
 */

// Vite's `import.meta.glob()` returns an object like:
//   { './components/Foo.vue': Module, './components/Bar.vue': Module }
// With `{ eager: true }`, those modules are imported at build time, so we can synchronously
// build a registry without async loading.
const eagerlyLoadedComponentModules = import.meta.glob('./components/*.vue', { eager: true });

// Convert `{ path: module }` into `{ componentName: componentDefinition }`.
// The component name is derived from the filename, so `./components/HelloWorld.vue` => `HelloWorld`.
const sortedComponentEntries = Object.entries(eagerlyLoadedComponentModules).sort(([leftPath], [rightPath]) => leftPath.localeCompare(rightPath));

const componentRegistryByName = sortedComponentEntries.reduce((registryByName, [modulePath, loadedModule]) => {
	const filename = modulePath.split('/').pop();
	const componentName = filename ? filename.replace(/\.vue$/, '') : modulePath;

	// Vue SFCs and most modules expose the component via `default` export.
	// Falling back to the module itself keeps this utility flexible for non-SFC exports.
	registryByName[componentName] = loadedModule && loadedModule.default ? loadedModule.default : loadedModule;
	return registryByName;
}, {});

/**
 * Returns a Vue component by its registered name.
 * The name is derived from the component filename inside `src/vue/components/`.
 */
export function getVueComponent(name) {
	return componentRegistryByName[name] || null;
}

/**
 * Exposes the full registry, mainly for debugging.
 */
export function listVueComponents() {
	return { ...componentRegistryByName };
}

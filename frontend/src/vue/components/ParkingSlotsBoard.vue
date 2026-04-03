<template>
	<div class="psb-root">
		<div v-if="toastMessage" class="psb-toast" :class="toastKindClass">
			{{ toastMessage }}
		</div>

		<div class="psb-meta">
			<span class="psb-meta__item">Date: <b>{{ selectedDate }}</b></span>
		</div>

		<div class="psb-board">
			<div class="psb-row psb-row--header">
				<div class="psb-cell psb-cell--spot">Spot</div>
				<div v-for="slot in slotHeaders" :key="slot.key" class="psb-cell psb-cell--slot-header">
					{{ slot.key }}
				</div>
			</div>

			<div class="psb-rows">
				<div v-if="isLoading" class="psb-loading">Loading slots…</div>
				<div v-else-if="spots.length === 0" class="psb-empty">No spots found.</div>
				<div v-else>
					<div v-for="spot in spots" :key="spot.id" class="psb-row">
						<div class="psb-cell psb-cell--spot">
							{{ spot.spotNumber }}
						</div>

						<button
							v-for="slot in spot.slots"
							:key="slot.key"
							class="psb-slot"
							:class="slot.expired ? 'psb-slot--expired' : (slot.taken ? 'psb-slot--taken' : 'psb-slot--available')"
							:disabled="slot.taken || slot.expired || isReserving"
							:title="slot.expired ? 'Slot time has passed' : (slot.taken ? 'Taken' : 'Available')"
							@click="reserveSlot(spot.id, slot)"
						>
							{{ slot.expired ? 'Expired' : (slot.taken ? 'Taken' : 'Available') }}
						</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApiClient from '../../api/ApiClient';
import { createEcho, createConnectionLifecycleController } from '../../realtime/EchoClient';

const props = defineProps({
	initialDate: {
		type: String,
		required: true
	}
});

const apiClient = new ApiClient();

const selectedDate = ref(props.initialDate);
const spots = ref([]);
const isLoading = ref(true);
const isReserving = ref(false);

const toastMessage = ref('');
const toastKind = ref('');
let toastTimer = null;

const connectionState = ref('connecting');

let echo = null;
let activeDateChannel = null;
let reconnectController = null;

const slotHeaders = computed(() => {
	const firstSpot = spots.value && spots.value.length > 0 ? spots.value[0] : null;
	return firstSpot && Array.isArray(firstSpot.slots) ? firstSpot.slots : [];
});

const toastKindClass = computed(() => {
	if (toastKind.value === 'success') return 'psb-toast--success';
	if (toastKind.value === 'error') return 'psb-toast--error';
	return 'psb-toast--info';
});

/**
 * Determine if a slot's end time (UTC) is already in the past.
 * This exists to handle the edge case where a user keeps the page open and a previously-available slot expires.
 */
function isSlotExpired(slot) {
	if (!slot || !slot.endUtc) return false;
	const endMs = Date.parse(slot.endUtc);
	if (Number.isNaN(endMs)) return false;
	return endMs <= Date.now();
}

/**
 * Mark a slot as expired in local state so the UI reflects that it can no longer be booked.
 */
function markSlotExpired(spotId, slotKey) {
	updateSlotInState(spotId, { key: slotKey, expired: true });
}

/**
 * Fetch the full slot availability snapshot via HTTP.
 */
async function loadSlotsForDate(date) {
	if (!date) return;

	isLoading.value = true;
	try {
		const data = await apiClient.request(`/slots?date=${encodeURIComponent(date)}`);
		const rawSpots = data && Array.isArray(data.spots) ? data.spots : [];
		spots.value = rawSpots.map((spot) => {
			const rawSlots = Array.isArray(spot.slots) ? spot.slots : [];
			return {
				...spot,
				slots: rawSlots.map((slot) => ({
					...slot,
					expired: isSlotExpired(slot)
				}))
			};
		});
	} catch (err) {
		const message = err && err.message ? err.message : 'Could not load slots.';
		setToast('error', message);
		spots.value = [];
	} finally {
		isLoading.value = false;
	}
}

/**
 * Show a single message to the user.
 * This exists to avoid stacking messages; a new message replaces the previous one.
 */
function setToast(kind, message, { autoHideMs = 3000 } = {}) {
	toastKind.value = kind;
	toastMessage.value = message;

	if (toastTimer) {
		// Only one message can be shown at a time, so we cancel the previous auto-hide timer.
		window.clearTimeout(toastTimer);
		toastTimer = null;
	}

	if (autoHideMs && kind !== 'error') {
		toastTimer = window.setTimeout(() => {
			toastTimer = null;
			clearToast();
		}, autoHideMs);
	}
}

/**
 * Clear the current message.
 */
function clearToast() {
	toastKind.value = '';
	toastMessage.value = '';
}

/**
 * Patch a single slot inside the local state so the UI updates without reloading.
 */
function updateSlotInState(spotId, slotUpdate) {
	if (!slotUpdate || !slotUpdate.key) return;

	const spotIndex = spots.value.findIndex((spot) => spot.id === spotId);
	if (spotIndex < 0) return;

	const spot = spots.value[spotIndex];
	const slots = Array.isArray(spot.slots) ? spot.slots : [];
	const slotIndex = slots.findIndex((slot) => slot.key === slotUpdate.key);
	if (slotIndex < 0) return;

	// Build a new slot object by merging the existing slot + the partial update.
	const updatedSlot = {
		...slots[slotIndex],
		...slotUpdate
	};

	// Replace only the changed slot in a shallow-copied slots array.
	const updatedSlots = slots.slice();
	updatedSlots[slotIndex] = updatedSlot;

	// Replace only the changed spot in a shallow-copied spots array.
	const updatedSpot = { ...spot, slots: updatedSlots };
	const updatedSpots = spots.value.slice();
	updatedSpots[spotIndex] = updatedSpot;

	spots.value = updatedSpots;
}

/**
 * Bind Pusher connection state changes to UI state and snapshot refresh.
 */
function bindConnectionStateHandlers() {
	const pusher = echo && echo.connector && echo.connector.pusher ? echo.connector.pusher : null;
	if (!pusher || !pusher.connection) return;

	pusher.connection.bind('state_change', (states) => {
		connectionState.value = states && states.current ? states.current : 'connecting';

		if (connectionState.value === 'connected') {
			if (reconnectController) reconnectController.reset();
			clearToast();
			return;
		}

		if (connectionState.value === 'disconnected' || connectionState.value === 'unavailable') {
			if (reconnectController) reconnectController.markConnectionLost();
			setToast('info', 'Reconnecting…');
			if (reconnectController) reconnectController.schedule();
		}
	});
}

/**
 * Leave the active date channel so we stop receiving updates for that date.
 */
function unsubscribeFromDate(date) {
	if (!echo || !date) return;
	try {
		echo.leave(`parking.slots.${date}`);
	} catch {
	}
}

/**
 * Subscribe to the date channel and request a snapshot once subscribed.
 */
function subscribeToDate(date) {
	if (!echo || !date) return;

	activeDateChannel = echo.private(`parking.slots.${date}`);

	activeDateChannel
		.listen('.parking.slots.slot-updated', (payload) => {
			if (!payload || payload.date !== date) return;
			if (!payload.slot || typeof payload.spotId !== 'number') return;
			updateSlotInState(payload.spotId, payload.slot);
		})
		.error(() => {
			setToast('error', 'Could not subscribe to live updates. Please refresh.', { autoHideMs: 0 });
		});
}

/**
 * Create a reservation using the slot's UTC boundaries provided by the backend.
 */
async function reserveSlot(spotId, slot) {
	if (!slot) return;
	if (slot.taken) return;

	// Prevent booking a slot that has already ended in UTC.
	// This is a UX guard for users who keep the page open and click a slot after it has expired.
	if (slot.expired || isSlotExpired(slot)) {
		setToast('error', 'Slot time has passed');
		markSlotExpired(spotId, slot.key);
		return;
	}

	clearToast();
	isReserving.value = true;

	try {
		const res = await apiClient.postJson('/reservations', {
			spot_id: spotId,
			start_time: slot.startUtc,
			end_time: slot.endUtc
		}, { acceptStatuses: [409] });

		if (res && res.__httpStatus === 409) {
			setToast('error', 'That slot was just taken. Please choose another.');
			updateSlotInState(spotId, { key: slot.key, taken: true });
			return;
		}

		setToast('success', 'Reservation created.');
	} catch (err) {
		if (err && err.message) {
			setToast('error', err.message);
		} else {
			setToast('error', 'Reservation failed.');
		}
	} finally {
		isReserving.value = false;
	}
}

/**
 * React to the global date select change by switching the subscribed channel.
 */
function handleDateChangeEvent(e) {
	const nextDate = e && e.detail ? String(e.detail) : '';
	if (!nextDate || nextDate === selectedDate.value) return;

	const previousDate = selectedDate.value;
	selectedDate.value = nextDate;
	loadSlotsForDate(nextDate);
	unsubscribeFromDate(previousDate);
	subscribeToDate(nextDate);
}

onMounted(async () => {
	await loadSlotsForDate(selectedDate.value);

	try {
		echo = createEcho();
	} catch (err) {
		const message = err && err.message ? err.message : 'Could not initialize realtime connection.';
		setToast('error', message, { autoHideMs: 0 });
		return;
	}

	const pusher = echo && echo.connector && echo.connector.pusher ? echo.connector.pusher : null;
	reconnectController = createConnectionLifecycleController({
		connect: () => {
			if (!pusher || typeof pusher.connect !== 'function') return;
			try {
				pusher.connect();
			} catch (err) {
				console.error('Could not connect to socket', err);
			}
		}
	});

	bindConnectionStateHandlers();
	subscribeToDate(selectedDate.value);
	window.addEventListener('parking-date-change', handleDateChangeEvent);
});

// Stop socket connection and clear all side effects:
// - cancel reconnect logic
// - clear pending UI timers
// - remove global event listeners
// - unsubscribe from active channel(s)
// - safely disconnect Echo (WebSocket)
// - reset local references to avoid memory leaks
onBeforeUnmount(() => {
	if (reconnectController) reconnectController.cleanup();
	reconnectController = null;
	if (toastTimer) {
		window.clearTimeout(toastTimer);
		toastTimer = null;
	}
	window.removeEventListener('parking-date-change', handleDateChangeEvent);
	unsubscribeFromDate(selectedDate.value);
	if (echo) {
		try {
			echo.disconnect();
		} catch (err) {
      console.error('Could not disconnect from socket', err);
		}
	}
	echo = null;
	activeDateChannel = null;
});
</script>

<style scoped>
.psb-root {
	width: 100%;
}

.psb-toast {
	position: fixed;
	top: 5rem;
	right: 1rem;
	max-width: min(420px, calc(100vw - 2rem));
	padding: 0.75rem 1rem;
	border-radius: 12px;
	border: 1px solid var(--border-color);
	font-weight: 600;
	box-shadow: var(--shadow-sm);
	z-index: 1000;
}

.psb-toast--success {
	background: #ecfdf5;
	border-color: #a7f3d0;
	color: #065f46;
}

.psb-toast--error {
	background: #fef2f2;
	border-color: #fecaca;
	color: #991b1b;
}

.psb-toast--info {
	background: #eff6ff;
	border-color: #bfdbfe;
	color: #1e40af;
}

.psb-slot--expired {
	background: #f3f4f6;
	border: 1px solid #d1d5db;
	color: #6b7280;
	cursor: not-allowed;
}

.psb-meta {
	display: flex;
	gap: 1rem;
	align-items: center;
	justify-content: space-between;
	width: 100%;
	margin-bottom: 1rem;
	color: var(--text-secondary);
	font-size: 0.95rem;
}

.psb-meta__item b {
	color: var(--text-main);
}

.psb-board {
	width: 100%;
	background: var(--surface-color);
	border: 1px solid var(--border-color);
	border-radius: var(--radius-lg);
	box-shadow: var(--shadow-sm);
	overflow: hidden;
	display: flex;
	flex-direction: column;
}

.psb-row {
	display: grid;
	grid-auto-flow: column;
	grid-template-columns: 160px;
	grid-auto-columns: 120px;
	width: max-content;
	min-width: 100%;
	align-items: stretch;
}

.psb-row--header {
	position: sticky;
	top: 0;
	background: rgba(255, 255, 255, 0.95);
	backdrop-filter: blur(6px);
	border-bottom: 1px solid var(--border-color);
}

.psb-rows {
	overflow: auto;
	max-height: 65vh;
}

.psb-cell {
	padding: 0.9rem 0.75rem;
	border-bottom: 1px solid var(--border-color);
	display: flex;
	align-items: center;
}

.psb-cell--spot {
	position: sticky;
	left: 0;
	background: white;
	font-weight: 700;
}

.psb-row--header .psb-cell--spot {
	background: rgba(255, 255, 255, 0.95);
}

.psb-cell--slot-header {
	justify-content: center;
	font-weight: 700;
	color: var(--text-main);
}

.psb-slot {
	height: auto;
	min-height: 52px;
	margin: 0;
	border-radius: 0;
	box-shadow: none;
	border-bottom: 1px solid var(--border-color);
	font-weight: 700;
	transform: none;
}

.psb-slot:hover {
	transform: none;
}

.psb-slot--available {
	background: #16a34a;
}

.psb-slot--available:hover {
	background: #15803d;
}

.psb-slot--taken {
	background: #ef4444;
}

.psb-slot--taken:hover {
	background: #ef4444;
}

.psb-loading,
.psb-empty {
	padding: 1.5rem;
	color: var(--text-secondary);
	text-align: center;
}

@media (max-width: 900px) {
	.psb-meta {
		flex-direction: column;
		align-items: flex-start;
		gap: 0.5rem;
	}

	.psb-board {
		max-height: 70vh;
	}
}
</style>

<template>
	<div class="psb-root">
		<div v-if="bannerMessage" class="psb-banner" :class="bannerKindClass">
			{{ bannerMessage }}
		</div>

		<div class="psb-meta">
			<span class="psb-meta__item">Date: <b>{{ selectedDate }}</b></span>
		</div>

		<div class="psb-board" :style="{ '--psb-cols': gridTemplateColumns }">
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
import { createEcho } from '../../realtime/EchoClient';

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

const bannerMessage = ref('');
const bannerKind = ref('');

const connectionState = ref('connecting');

let echo = null;
let activeDateChannel = null;
let reconnectTimer = null;
let reconnectAttempt = 0;

const slotHeaders = computed(() => {
	const firstSpot = spots.value && spots.value.length > 0 ? spots.value[0] : null;
	return firstSpot && Array.isArray(firstSpot.slots) ? firstSpot.slots : [];
});

const gridTemplateColumns = computed(() => {
	const slotCount = slotHeaders.value.length;
	const slotPart = slotCount > 0 ? `repeat(${slotCount}, minmax(120px, 1fr))` : 'minmax(120px, 1fr)';
	return `160px ${slotPart}`;
});

const bannerKindClass = computed(() => {
	if (bannerKind.value === 'success') return 'psb-banner--success';
	if (bannerKind.value === 'error') return 'psb-banner--error';
	return 'psb-banner--info';
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
		setBanner('error', message);
		spots.value = [];
	} finally {
		isLoading.value = false;
	}
}

/**
 * Set a banner message shown above the board.
 */
function setBanner(kind, message) {
	bannerKind.value = kind;
	bannerMessage.value = message;
}

/**
 * Clear the current banner.
 */
function clearBanner() {
	bannerKind.value = '';
	bannerMessage.value = '';
}

/**
 * Patch a single slot inside the local state so the UI updates without reloading.
 */
function updateSlotInState(spotId, slotUpdate) {
  // Find the spot from all spots.
	const spotIndex = spots.value.findIndex((spot) => spot.id === spotId);
	if (spotIndex === -1) return;

  // Find the slot from the spot by the key.
	const spot = spots.value[spotIndex];
	const slotIndex = Array.isArray(spot.slots) ? spot.slots.findIndex((slot) => slot.key === slotUpdate.key) : -1;
	if (slotIndex === -1) return;

	const updatedSlots = [...spot.slots];
	updatedSlots[slotIndex] = {
		...updatedSlots[slotIndex],
		...slotUpdate
	};

	const updatedSpot = {
		...spot,
		slots: updatedSlots
	};

	const updatedSpots = [...spots.value];
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
			cleanupReconnect();
			reconnectAttempt = 0;
			clearBanner();
			return;
		}

		if (connectionState.value === 'disconnected' || connectionState.value === 'unavailable') {
			setBanner('info', 'Reconnecting…');
			scheduleReconnect();
		}
	});
}

/**
 * Schedule an explicit reconnect attempt with exponential backoff.
 * This exists to keep the UI resilient if the underlying websocket drops.
 */
function scheduleReconnect() {
	if (reconnectTimer) return;

	const delayMs = Math.min(5000, 500 * Math.pow(2, reconnectAttempt));
	reconnectAttempt += 1;

	reconnectTimer = window.setTimeout(() => {
		reconnectTimer = null;
		const pusher = echo && echo.connector && echo.connector.pusher ? echo.connector.pusher : null;
		if (pusher && typeof pusher.connect === 'function') {
			pusher.connect();
		}
	}, delayMs);
}

/**
 * Cancel a pending reconnect timer, if any.
 */
function cleanupReconnect() {
	if (!reconnectTimer) return;
	window.clearTimeout(reconnectTimer);
	reconnectTimer = null;
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
			setBanner('error', 'Could not subscribe to live updates. Please refresh.');
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
		setBanner('error', 'Slot time has passed');
		markSlotExpired(spotId, slot.key);
		return;
	}

	clearBanner();
	isReserving.value = true;

	try {
		const res = await apiClient.postJson('/reservations', {
			spot_id: spotId,
			start_time: slot.startUtc,
			end_time: slot.endUtc
		}, { acceptStatuses: [409] });

		if (res && res.__httpStatus === 409) {
			setBanner('error', 'That slot was just taken. Please choose another.');
			updateSlotInState(spotId, { key: slot.key, taken: true });
			return;
		}

		setBanner('success', 'Reservation created.');
	} catch (err) {
		if (err && err.message) {
			setBanner('error', err.message);
		} else {
			setBanner('error', 'Reservation failed.');
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
		setBanner('error', message);
		return;
	}

	bindConnectionStateHandlers();
	subscribeToDate(selectedDate.value);
	window.addEventListener('parking-date-change', handleDateChangeEvent);
});

onBeforeUnmount(() => {
	cleanupReconnect();
	window.removeEventListener('parking-date-change', handleDateChangeEvent);
	unsubscribeFromDate(selectedDate.value);
	if (echo) {
		try {
			echo.disconnect();
		} catch {
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

.psb-banner {
	width: 100%;
	padding: 0.75rem 1rem;
	border-radius: 12px;
	border: 1px solid var(--border-color);
	margin-bottom: 1rem;
	font-weight: 600;
}

.psb-banner--success {
	background: #ecfdf5;
	border-color: #a7f3d0;
	color: #065f46;
}

.psb-banner--error {
	background: #fef2f2;
	border-color: #fecaca;
	color: #991b1b;
}

.psb-banner--info {
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
	grid-template-columns: var(--psb-cols);
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

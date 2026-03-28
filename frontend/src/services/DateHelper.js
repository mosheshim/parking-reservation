export function getNextDays(count = 3) {
	const days = [];
	const labels = ['Today', 'Tomorrow', 'Day after tomorrow'];
	const today = new Date();

	for (let i = 0; i < count; i++) {
		const date = new Date(today);
		date.setDate(today.getDate() + i);

		days.push({
			value: date.toISOString().split('T')[0],
			label: labels[i] || date.toLocaleDateString(),
			displayDate: date.toLocaleDateString('en-GB')
		});
	}

	return days;
}
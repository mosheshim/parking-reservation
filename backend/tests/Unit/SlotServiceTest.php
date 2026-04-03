<?php

namespace Tests\Unit;

use App\Events\ParkingSlotStatusChanged;
use App\Services\SlotService;
use App\ValueObjects\SlotAvailability;
use App\ValueObjects\SpotSlotAvailability;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SlotServiceTest extends TestCase
{
    /**
     * Verifies that slot time definitions are exposed as value objects.
     */
    public function test_get_slot_time_definitions_returns_value_objects(): void
    {
        $service = app(SlotService::class);
        $definitions = $service->getSlotTimeDefinitions();

        $this->assertCount(3, $definitions);
        $this->assertSame('08:00', $definitions[0]->start);
        $this->assertSame('12:00', $definitions[0]->end);
        $this->assertSame('12:00', $definitions[1]->start);
        $this->assertSame('16:00', $definitions[1]->end);
    }

    /**
     * Ensures slot definitions include a stable key and precomputed UTC boundaries.
     */
    public function test_get_slot_definitions_for_date_includes_key_and_utc_boundaries(): void
    {
        $service = app(SlotService::class);

        $timezone = new DateTimeZone($service->getSlotTimezone());
        $date = Carbon::parse('2026-04-02', $timezone);

        $definitions = $service->getSlotDefinitionsForDate($date, $timezone);

        $this->assertCount(3, $definitions);
        $this->assertSame('08:00 - 12:00', $definitions[0]->key);
        $this->assertTrue($definitions[0]->startUtc->isUtc());
        $this->assertTrue($definitions[0]->endUtc->isUtc());

        // UTC boundaries must match the local slot boundary converted to UTC.
        $expectedStartUtc = Carbon::parse('2026-04-02 08:00:00', $timezone)->utc()->toISOString();
        $expectedEndUtc = Carbon::parse('2026-04-02 12:00:00', $timezone)->utc()->toISOString();

        $this->assertSame($expectedStartUtc, $definitions[0]->startUtc->toISOString());
        $this->assertSame($expectedEndUtc, $definitions[0]->endUtc->toISOString());
    }

    /**
     * Verifies overlap detection returns the expected slot indexes for a UTC range.
     */
    public function test_get_overlapping_slot_indexes_returns_expected_indexes(): void
    {
        $service = app(SlotService::class);
        $timezone = new DateTimeZone($service->getSlotTimezone());
        $date = Carbon::parse('2026-04-02', $timezone);

        $definitions = $service->getSlotDefinitionsForDate($date, $timezone);

        $startUtc = Carbon::parse('2026-04-02 10:00:00', $timezone)->utc();
        $endUtc = Carbon::parse('2026-04-02 14:00:00', $timezone)->utc();

        $this->assertSame([0, 1], $service->getOverlappingSlotIndexes($definitions, $startUtc, $endUtc));
    }

    /**
     * Ensures broadcasting spot slot availability emits events per spot+slot with the expected payload.
     */
    public function test_broadcast_spot_slot_availability_dispatches_events(): void
    {
        Event::fake([ParkingSlotStatusChanged::class]);

        $service = app(SlotService::class);

        $availability = new SpotSlotAvailability(
            id: 1,
            spotNumber: 'A-01',
            slots: [
                new SlotAvailability(
                    key: '08:00 - 12:00',
                    start: '08:00',
                    end: '12:00',
                    startUtc: '2026-04-02T05:00:00Z',
                    endUtc: '2026-04-02T09:00:00Z',
                    taken: false,
                ),
            ],
        );

        $service->broadcastSpotSlotAvailability('2026-04-02', [$availability]);

        Event::assertDispatched(ParkingSlotStatusChanged::class, 1);
        Event::assertDispatched(ParkingSlotStatusChanged::class, function (ParkingSlotStatusChanged $event): bool {
            return $event->date === '2026-04-02'
                && $event->spotId === 1
                && $event->slotKey === '08:00 - 12:00'
                && $event->startUtc === '2026-04-02T05:00:00Z'
                && $event->endUtc === '2026-04-02T09:00:00Z'
                && $event->taken === false;
        });
    }
}

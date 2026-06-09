<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;

readonly class ReportingFilterContext
{
    public function __construct(
        public CarbonImmutable $periodStartUtc,
        public CarbonImmutable $periodEndUtc,
        public CarbonImmutable $compareStartUtc,
        public CarbonImmutable $compareEndUtc,
        public string $timezone,
        public int $dayCount,
        public ?int $shiftId,
        public ?int $mealTypeId,
        public ?int $dayOfWeek,
        public ?string $statusFilter,
        public string $frequencyPeriod,
        public int $page,
        public int $perPage,
        public bool $export,
    ) {}
}

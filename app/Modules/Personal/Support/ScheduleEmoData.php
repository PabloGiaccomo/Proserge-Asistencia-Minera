<?php

namespace App\Modules\Personal\Support;

use Carbon\CarbonImmutable;

final readonly class ScheduleEmoData
{
    public function __construct(
        public CarbonImmutable $scheduledAt,
        public string $clinicId,
        public ?string $observation = null,
        public EmoOrigin $origin = EmoOrigin::MANUAL,
        public ?string $sourceCourseRecordId = null,
    ) {}
}

<?php

namespace App\Modules\Personal\Support;

use Carbon\CarbonImmutable;

final readonly class ReprogramEmoData
{
    public function __construct(
        public CarbonImmutable $scheduledAt,
        public string $clinicId,
        public string $reason,
        public EmoOrigin $origin,
    ) {}
}

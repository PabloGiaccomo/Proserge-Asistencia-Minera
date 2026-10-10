<?php

namespace App\Modules\Personal\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

final readonly class CompleteEmoData
{
    public function __construct(
        public CarbonImmutable $performedAt,
        public EmoOutcome $outcome,
        public ?string $detail = null,
        public ?string $score = null,
        public ?CarbonImmutable $manualExpiration = null,
        public ?string $observation = null,
        public ?UploadedFile $file = null,
        public EmoOrigin $origin = EmoOrigin::MANUAL,
        public ?string $sourceCourseRecordId = null,
    ) {}
}

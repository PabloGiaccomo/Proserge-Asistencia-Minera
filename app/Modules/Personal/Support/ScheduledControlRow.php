<?php

namespace App\Modules\Personal\Support;

use Carbon\CarbonImmutable;

final readonly class ScheduledControlRow
{
    /**
     * @param  list<string>  $mineIds
     * @param  list<string>  $mineNames
     * @param  list<string>  $attemptLabels
     */
    public function __construct(
        public string $rowKey,
        public string $sourceType,
        public CarbonImmutable $scheduledDate,
        public ?string $clinicId,
        public ?string $clinicName,
        public ?int $appointmentRevision,
        public string $workerId,
        public string $workerDocument,
        public string $workerName,
        public ?string $positionName,
        public string $examId,
        public string $examName,
        public array $mineIds,
        public array $mineNames,
        public array $attemptLabels,
        public string $attendanceState,
        public ?EmoOutcome $emoOutcome,
        public ?string $resultDetail,
        public ?CarbonImmutable $performedAt,
        public string $operationalState,
    ) {}
}

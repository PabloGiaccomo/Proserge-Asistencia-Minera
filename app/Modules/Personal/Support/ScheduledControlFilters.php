<?php

namespace App\Modules\Personal\Support;

use App\Modules\Personal\Requests\PersonalMinaScheduledControlRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class ScheduledControlFilters
{
    private function __construct(
        public ?string $worker,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?ScheduledControlCategory $category,
        public ?string $examId,
        public ?string $mineId,
        public ?string $clinicId,
        public ?ScheduledAttendanceState $attendanceState,
        public int $limit,
        public int $page,
    ) {}

    public static function fromValidated(array $data, CarbonImmutable $today): self
    {
        $today = $today->setTimezone(config('app.display_timezone', 'America/Lima'))->startOfDay();
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value) === '' ? null : trim($value);
            }
        }
        $data['scheduled_from'] ??= $today->subDays(30)->toDateString();
        $data['scheduled_to'] ??= $today->addDays(60)->toDateString();
        $validated = Validator::make($data, (new PersonalMinaScheduledControlRequest)->rules())->validate();
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $validated['scheduled_from'], $today->timezone);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $validated['scheduled_to'], $today->timezone);
        if ($to->lessThan($from) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages([
                'scheduled_to' => 'Seleccione un rango ordenado de hasta 366 dias, incluyendo ambas fechas.',
            ]);
        }

        return new self(
            $validated['scheduled_worker'] ?? null, $from, $to,
            isset($validated['scheduled_category']) ? ScheduledControlCategory::from($validated['scheduled_category']) : null,
            $validated['scheduled_exam_id'] ?? null, $validated['scheduled_mine_id'] ?? null,
            $validated['scheduled_clinic_id'] ?? null,
            isset($validated['scheduled_attendance_state']) ? ScheduledAttendanceState::from($validated['scheduled_attendance_state']) : null,
            (int) ($validated['scheduled_limit'] ?? 25), (int) ($validated['scheduled_page'] ?? 1),
        );
    }

    public function toQuery(bool $includePagination = true): array
    {
        $data = [
            'scheduled_worker' => $this->worker, 'scheduled_from' => $this->from->toDateString(),
            'scheduled_to' => $this->to->toDateString(), 'scheduled_category' => $this->category?->value,
            'scheduled_exam_id' => $this->examId, 'scheduled_mine_id' => $this->mineId,
            'scheduled_clinic_id' => $this->clinicId, 'scheduled_attendance_state' => $this->attendanceState?->value,
        ];
        if ($includePagination) {
            $data += ['scheduled_limit' => $this->limit, 'scheduled_page' => $this->page];
        }

        return array_filter($data, fn ($value): bool => $value !== null);
    }
}

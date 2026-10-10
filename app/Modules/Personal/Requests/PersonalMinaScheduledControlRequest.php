<?php

namespace App\Modules\Personal\Requests;

use App\Modules\Personal\Support\ScheduledAttendanceState;
use App\Modules\Personal\Support\ScheduledControlCategory;
use App\Modules\Personal\Support\ScheduledControlFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class PersonalMinaScheduledControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Web middleware and the controller enforce the action's permissions.
        return true;
    }

    public function rules(): array
    {
        return [
            'scheduled_worker' => ['nullable', 'string', 'max:100'],
            'scheduled_from' => ['nullable', 'date_format:Y-m-d'],
            'scheduled_to' => ['nullable', 'date_format:Y-m-d'],
            'scheduled_category' => ['nullable', Rule::enum(ScheduledControlCategory::class)],
            'scheduled_exam_id' => ['nullable', 'uuid'],
            'scheduled_mine_id' => ['nullable', 'uuid'],
            'scheduled_clinic_id' => ['nullable', 'uuid'],
            'scheduled_attendance_state' => ['nullable', Rule::enum(ScheduledAttendanceState::class)],
            'scheduled_limit' => ['nullable', 'integer', Rule::in([25, 50, 100])],
            'scheduled_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (array_keys($this->rules()) as $key) {
            $value = $this->input($key);
            $data[$key] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }
        $this->merge($data);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            try {
                ScheduledControlFilters::fromValidated($validator->validated(), CarbonImmutable::now());
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $key => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($key, $message);
                    }
                }
            }
        });
    }
}

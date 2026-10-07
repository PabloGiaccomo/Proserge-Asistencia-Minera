<?php

namespace App\Modules\Epps\Requests;

use App\Modules\Epps\Support\EppSignaturePayload;
use Illuminate\Foundation\Http\FormRequest;

class SignEppOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The confirmation service rechecks the direct permission and current scope.
        return true;
    }

    public function rules(): array
    {
        return EppSignaturePayload::rules();
    }

    public function signaturePayload(): EppSignaturePayload
    {
        return EppSignaturePayload::fromArray($this->validated());
    }
}

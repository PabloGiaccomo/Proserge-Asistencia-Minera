<?php

namespace App\Modules\Personal\Requests;

use App\Support\Rbac\PermissionMatrix;
use Illuminate\Foundation\Http\FormRequest;

abstract class MiningEmoFormRequest extends FormRequest
{
    final public function authorize(): bool
    {
        return PermissionMatrix::allowsDirect(
            session('user.permissions', []),
            'habilitacion_minera',
            $this->requiredPermission(),
        );
    }

    abstract protected function requiredPermission(): string;
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReserveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid'],
            'tickets' => ['required', 'array', 'min:1', 'max:10'],
            'tickets.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    /** @return list<string> */
    public function ticketIds(): array
    {
        return array_values($this->validated('tickets'));
    }
}

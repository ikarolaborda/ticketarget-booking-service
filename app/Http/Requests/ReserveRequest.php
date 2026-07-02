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
            'user_id' => [$this->attributes->has('auth_user_id') ? 'nullable' : 'required', 'uuid'],
            'tickets' => ['required', 'array', 'min:1', 'max:10'],
            'tickets.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    public function buyerId(): string
    {
        return (string) ($this->attributes->get('auth_user_id') ?? $this->validated('user_id'));
    }

    /** @return list<string> */
    public function ticketIds(): array
    {
        return array_values($this->validated('tickets'));
    }
}

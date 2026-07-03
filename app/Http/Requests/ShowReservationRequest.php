<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ShowReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => [$this->attributes->has('auth_user_id') ? 'nullable' : 'required', 'uuid'],
        ];
    }

    public function buyerId(): string
    {
        return (string) ($this->attributes->get('auth_user_id') ?? $this->validated('user_id'));
    }
}

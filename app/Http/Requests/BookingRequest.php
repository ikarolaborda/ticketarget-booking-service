<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $authenticated = $this->attributes->has('auth_user_id');

        return [
            'reservation_id' => ['required', 'uuid'],
            // Authenticated identity comes from the verified token, never the body.
            'user_id' => [$authenticated ? 'nullable' : 'required', 'uuid'],
            'email' => [$authenticated ? 'nullable' : 'required', 'email:rfc', 'max:254'],
            'payment_token' => ['required', 'string'],
        ];
    }

    public function buyerId(): string
    {
        return (string) ($this->attributes->get('auth_user_id') ?? $this->validated('user_id'));
    }

    public function buyerEmail(): string
    {
        return (string) ($this->attributes->get('auth_email')
            ?? mb_strtolower(trim((string) $this->validated('email'))));
    }
}

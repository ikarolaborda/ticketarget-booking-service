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
        return [
            'reservation_id' => ['required', 'uuid'],
            'user_id' => ['required', 'uuid'],
            'payment_token' => ['required', 'string'],
        ];
    }
}

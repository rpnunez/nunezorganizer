<?php

namespace App\Http\Requests\Api;

use App\Rules\ValidIanaTimezone;
use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is handled by TaskPolicy in the controller.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],

            'occurrence' => ['sometimes', 'array:starts_at,ends_at,timezone'],
            'occurrence.starts_at' => ['required_with:occurrence', 'date'],
            'occurrence.ends_at' => ['required_with:occurrence', 'date'],
            'occurrence.timezone' => ['required_with:occurrence', 'string', new ValidIanaTimezone],

            // Ownership and lifecycle are never client-controlled.
            'account_id' => ['prohibited'],
            'owner_user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'completed_at' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
        ];
    }
}

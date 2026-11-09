<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:1440'],

            // Ownership transfers and status changes are not possible through edits.
            'account_id' => ['prohibited'],
            'owner_user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'completed_at' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
        ];
    }
}

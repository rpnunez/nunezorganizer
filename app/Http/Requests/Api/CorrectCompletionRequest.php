<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CorrectCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // TaskPolicy is applied in the controller.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'completed_at' => ['required', 'date'],
        ];
    }
}

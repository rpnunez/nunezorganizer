<?php

namespace App\Http\Requests\Api;

use App\Rules\ValidIanaTimezone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Schedule or move a block: ISO-8601 datetimes plus the IANA zone they're planned in.
 */
class OccurrenceTimesRequest extends FormRequest
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
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'timezone' => ['required', 'string', new ValidIanaTimezone],
            'account_id' => ['prohibited'],
            'task_id' => ['prohibited'],
            'state' => ['prohibited'],
            'version' => ['prohibited'],
        ];
    }
}

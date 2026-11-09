<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FbSubmissionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_code' => $this->reference_code,
            'event_id' => $this->feedback?->event_id ?? $this->event_id,
            'feedback_id' => $this->feedback_id,
            'participant' => [
                'id' => $this->participant?->id,
                'name' => $this->participant?->name,
                'display_name' => $this->participant?->display_name ?? 'Anonymous Participant',
                'is_anonymous' => $this->participant?->isAnonymous() ?? ($this->participant?->name === null),
                'agency' => $this->participant?->agency,
                'designation' => $this->participant?->designation,
                'years_in_designation' => $this->participant?->years_in_designation,
                'location' => $this->participant?->location,
                'no_of_exercises' => $this->participant?->no_of_exercises,
                'custom_function' => $this->participant?->custom_function,
                'function' => $this->participant?->function ? [
                    'id' => $this->participant->function->id,
                    'function' => $this->participant->function->function,
                    'details' => $this->participant->function->details,
                ] : null,
            ],
            'data' => $this->sanitizeSubmissionData($this->data),
            'submitted_at' => $this->submitted_at?->toIso8601String() ?? $this->created_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Redact internal storage disk/path attributes from photo answers in public submission responses.
     *
     * @return array<string, mixed>|null
     */
    protected function sanitizeSubmissionData(mixed $data): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_array($value) && ($value['type'] ?? null) === 'photo') {
                $sanitized[$key] = [
                    'type' => 'photo',
                    'uploaded' => ! empty($value['path']),
                    'filename' => $value['filename'] ?? (isset($value['path']) ? basename((string) $value['path']) : null),
                    'mime_type' => $value['mime_type'] ?? null,
                    'size' => $value['size'] ?? null,
                    'original_name' => $value['original_name'] ?? null,
                ];
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}

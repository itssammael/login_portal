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
            'data' => $this->data,
            'submitted_at' => $this->submitted_at?->toIso8601String() ?? $this->created_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

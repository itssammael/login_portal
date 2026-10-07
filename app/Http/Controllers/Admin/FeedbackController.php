<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveFeedbackFormRequest;
use App\Http\Requests\Admin\StoreFbEventRequest;
use App\Http\Requests\Admin\StoreFbFunctionRequest;
use App\Http\Requests\Admin\UpdateFbEventRequest;
use App\Http\Requests\Admin\UpdateFbFunctionRequest;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Designation;
use App\Models\FbEvent;
use App\Models\FbFunction;
use App\Models\FbSubmission;
use App\Models\Feedback;
use App\Services\FeedbackSchemaValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FeedbackController extends Controller
{
    /**
     * Display the Feedback Setup administration page.
     */
    public function setup(Request $request): Response
    {
        $events = FbEvent::query()
            ->with(['creator:id,name,email', 'feedback', 'functions:id,function,details', 'embed'])
            ->withCount(['feedback', 'functions'])
            ->latest()
            ->get();

        $functions = FbFunction::query()
            ->with(['events:id,name', 'creator:id,name,email'])
            ->withCount(['participants', 'events'])
            ->latest()
            ->get();

        $feedbackForms = Feedback::query()
            ->with(['event' => fn ($q) => $q->withTrashed()])
            ->withCount('submissions')
            ->latest()
            ->get();

        $agencies = Agency::query()->orderBy('name')->get();
        $designations = Designation::query()->orderBy('name')->get();

        $stats = [
            'total_events' => $events->count(),
            'total_functions' => $functions->count(),
            'total_forms' => $feedbackForms->count(),
            'total_agencies' => $agencies->count(),
            'total_designations' => $designations->count(),
            'total_submissions' => FbSubmission::count(),
        ];

        return Inertia::render('Admin/Feedback/Setup', [
            'events' => $events,
            'functions' => $functions,
            'feedbackForms' => $feedbackForms,
            'agencies' => $agencies,
            'designations' => $designations,
            'stats' => $stats,
        ]);
    }

    /**
     * Display the Feedback Submissions listing and response review page.
     */
    public function submissions(Request $request): Response
    {
        $query = FbSubmission::query()
            ->with([
                'feedback.event' => fn ($q) => $q->withTrashed(),
                'participant.function',
            ]);

        if ($request->filled('event_id')) {
            $eventId = (int) $request->input('event_id');
            $query->whereHas('feedback', fn ($q) => $q->where('event_id', $eventId));
        }

        if ($request->filled('agency')) {
            $agency = (string) $request->input('agency');
            $query->whereHas('participant', fn ($q) => $q->where('agency', $agency));
        }

        if ($request->filled('designation')) {
            $designation = (string) $request->input('designation');
            $query->whereHas('participant', fn ($q) => $q->where('designation', $designation));
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->whereHas('participant', function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('agency', 'like', "%{$search}%")
                    ->orWhere('designation', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        $submissions = $query->latest()->paginate(15)->withQueryString();

        $filterEvents = FbEvent::withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at']);
        $filterAgencies = Agency::getCachedList();
        $filterDesignations = Designation::getCachedList();

        return Inertia::render('Admin/Feedback/Submissions', [
            'submissions' => $submissions,
            'filterEvents' => $filterEvents,
            'filterAgencies' => $filterAgencies,
            'filterDesignations' => $filterDesignations,
            'filters' => $request->only(['event_id', 'agency', 'designation', 'search']),
        ]);
    }

    /**
     * Store a newly created feedback event.
     */
    public function storeEvent(StoreFbEventRequest $request): RedirectResponse
    {
        $admin = $request->user();

        $event = FbEvent::create([
            'name' => $request->name,
            'details' => $request->details,
            'created_by' => $admin->id,
        ]);

        if ($request->has('function_ids')) {
            $event->functions()->sync($request->input('function_ids', []));
        }

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'created_fb_event',
            'target_type' => FbEvent::class,
            'target_id' => $event->id,
            'details' => [
                'name' => $event->name,
                'assigned_functions_count' => count($request->input('function_ids', [])),
            ],
        ]);

        return back()->with('success', "Event '{$event->name}' created successfully.");
    }

    /**
     * Update an existing feedback event.
     */
    public function updateEvent(UpdateFbEventRequest $request, FbEvent $event): RedirectResponse
    {
        $admin = $request->user();

        $event->update([
            'name' => $request->name,
            'details' => $request->details,
        ]);

        if ($request->has('function_ids')) {
            $event->functions()->sync($request->input('function_ids', []));
        }

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'updated_fb_event',
            'target_type' => FbEvent::class,
            'target_id' => $event->id,
            'details' => [
                'name' => $event->name,
            ],
        ]);

        return back()->with('success', "Event '{$event->name}' updated successfully.");
    }

    /**
     * Regenerate API key for an event.
     */
    public function regenerateApiKey(Request $request, FbEvent $event): RedirectResponse
    {
        $admin = $request->user();
        $newKey = FbEvent::generateUniqueApiKey();

        $event->update(['api_key' => $newKey]);

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'regenerated_fb_event_api_key',
            'target_type' => FbEvent::class,
            'target_id' => $event->id,
            'details' => [
                'name' => $event->name,
            ],
        ]);

        return back()->with('success', "API Key for '{$event->name}' has been regenerated successfully.");
    }

    /**
     * Regenerate public embed UUID for an event.
     */
    public function regenerateEmbedId(Request $request, FbEvent $event): RedirectResponse
    {
        $admin = $request->user();
        $embed = $event->embed ?: $event->embed()->create();
        $newPublicId = $embed->regeneratePublicId();

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'regenerated_feedback_embed_id',
            'target_type' => FbEvent::class,
            'target_id' => $event->id,
            'details' => [
                'event_name' => $event->name,
                'public_id' => $newPublicId,
            ],
        ]);

        return back()->with('success', "Public embed ID for '{$event->name}' has been regenerated. Previous iframe embeds have been invalidated.");
    }

    /**
     * Regenerate client secret for an event embed integration.
     */
    public function regenerateEmbedSecret(Request $request, FbEvent $event): RedirectResponse
    {
        $admin = $request->user();
        $embed = $event->embed ?: $event->embed()->create();
        $newSecret = $embed->regenerateSecret();

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'regenerated_feedback_embed_secret',
            'target_type' => FbEvent::class,
            'target_id' => $event->id,
            'details' => [
                'event_name' => $event->name,
                'client_id' => $embed->client_id,
            ],
        ]);

        return back()->with([
            'success' => "Client secret for '{$event->name}' has been regenerated. Please store it securely.",
            'revealed_secret' => [
                'event_id' => $event->id,
                'client_id' => $embed->client_id,
                'client_secret' => $newSecret,
            ],
        ]);
    }

    /**
     * Update embed allowed origins and status for an event.
     */
    public function updateEmbedOrigins(Request $request, FbEvent $event): RedirectResponse
    {
        $admin = $request->user();
        $request->validate([
            'allowed_origins' => ['nullable', 'array'],
            'allowed_origins.*' => ['string', 'max:255', 'regex:/^https?:\/\/[a-zA-Z0-9.-]+(?::[1-9][0-9]{0,4})?$/'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $embed = $event->embed ?: $event->embed()->create();
        $embed->update([
            'allowed_origins' => $request->input('allowed_origins'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'updated_feedback_embed_origins',
            'target_type' => FbEvent::class,
            'target_id' => $event->id,
            'details' => [
                'event_name' => $event->name,
                'allowed_origins' => $embed->allowed_origins,
                'is_active' => $embed->is_active,
            ],
        ]);

        return back()->with('success', "Embed settings for '{$event->name}' updated successfully.");
    }

    /**
     * Soft-delete a feedback event.
     */
    public function destroyEvent(Request $request, FbEvent $event): RedirectResponse
    {
        $admin = $request->user();
        $name = $event->name;

        $event->delete();

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'deleted_fb_event',
            'target_type' => FbEvent::class,
            'target_id' => $event->id,
            'details' => [
                'name' => $name,
            ],
        ]);

        return back()->with('success', "Event '{$name}' deleted successfully.");
    }

    /**
     * Store a newly created participant function (reusable across events).
     */
    public function storeFunction(StoreFbFunctionRequest $request): RedirectResponse
    {
        $admin = $request->user();

        $function = FbFunction::create([
            'function' => $request->input('function'),
            'details' => $request->input('details'),
            'created_by' => $admin->id,
        ]);

        $eventIds = $request->input('event_ids', []);
        if (empty($eventIds) && $request->filled('event_id')) {
            $eventIds = [(int) $request->input('event_id')];
        }

        if (! empty($eventIds)) {
            $function->events()->sync($eventIds);
        }

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'created_fb_function',
            'target_type' => FbFunction::class,
            'target_id' => $function->id,
            'details' => [
                'function' => $function->function,
                'events_count' => count($eventIds),
            ],
        ]);

        return back()->with('success', "Function '{$function->function}' created successfully.");
    }

    /**
     * Update an existing participant function.
     */
    public function updateFunction(UpdateFbFunctionRequest $request, FbFunction $function): RedirectResponse
    {
        $admin = $request->user();

        $function->update([
            'function' => $request->input('function'),
            'details' => $request->input('details'),
        ]);

        if ($request->has('event_ids')) {
            $function->events()->sync($request->input('event_ids', []));
        } elseif ($request->filled('event_id')) {
            $function->events()->sync([(int) $request->input('event_id')]);
        }

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'updated_fb_function',
            'target_type' => FbFunction::class,
            'target_id' => $function->id,
            'details' => [
                'function' => $function->function,
            ],
        ]);

        return back()->with('success', "Function '{$function->function}' updated successfully.");
    }

    /**
     * Delete a participant function.
     */
    public function destroyFunction(Request $request, FbFunction $function): RedirectResponse
    {
        $admin = $request->user();

        $hasSubmissions = FbSubmission::whereHas('participant', function ($q) use ($function): void {
            $q->where('function_id', $function->id);
        })->exists();

        if ($hasSubmissions) {
            return back()->with('error', "Cannot delete function '{$function->function}' because participants under this function have submitted feedback.");
        }

        $name = $function->function;
        $function->delete();

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'deleted_fb_function',
            'target_type' => FbFunction::class,
            'target_id' => $function->id,
            'details' => [
                'function' => $name,
            ],
        ]);

        return back()->with('success', "Function '{$name}' deleted successfully.");
    }

    /**
     * Store a newly created agency.
     */
    public function storeAgency(Request $request): RedirectResponse
    {
        $admin = $request->user();
        $request->merge([
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name'),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('agencies', 'name')->whereNull('deleted_at')],
        ]);

        $name = $validated['name'];
        $trashed = Agency::onlyTrashed()->where('name', $name)->first();

        if ($trashed) {
            $trashed->restore();
            $agency = $trashed;
        } else {
            $agency = Agency::create([
                'name' => $name,
                'created_by' => $admin->id,
            ]);
        }

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'created_agency',
            'target_type' => Agency::class,
            'target_id' => $agency->id,
            'details' => [
                'name' => $agency->name,
            ],
        ]);

        return back()->with('success', "Agency '{$agency->name}' added to dropdown list.");
    }

    /**
     * Delete an agency.
     */
    public function destroyAgency(Request $request, Agency $agency): RedirectResponse
    {
        $admin = $request->user();
        $name = $agency->name;
        $agency->delete();

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'deleted_agency',
            'target_type' => Agency::class,
            'target_id' => $agency->id,
            'details' => [
                'name' => $name,
            ],
        ]);

        return back()->with('success', "Agency '{$name}' removed.");
    }

    /**
     * Store a newly created designation.
     */
    public function storeDesignation(Request $request): RedirectResponse
    {
        $admin = $request->user();
        $request->merge([
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name'),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('designations', 'name')->whereNull('deleted_at')],
        ]);

        $name = $validated['name'];
        $trashed = Designation::onlyTrashed()->where('name', $name)->first();

        if ($trashed) {
            $trashed->restore();
            $designation = $trashed;
        } else {
            $designation = Designation::create([
                'name' => $name,
                'created_by' => $admin->id,
            ]);
        }

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'created_designation',
            'target_type' => Designation::class,
            'target_id' => $designation->id,
            'details' => [
                'name' => $designation->name,
            ],
        ]);

        return back()->with('success', "Designation '{$designation->name}' added to dropdown list.");
    }

    /**
     * Delete a designation.
     */
    public function destroyDesignation(Request $request, Designation $designation): RedirectResponse
    {
        $admin = $request->user();
        $name = $designation->name;
        $designation->delete();

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'deleted_designation',
            'target_type' => Designation::class,
            'target_id' => $designation->id,
            'details' => [
                'name' => $name,
            ],
        ]);

        return back()->with('success', "Designation '{$name}' removed.");
    }

    /**
     * Save or update a feedback form schema for an event.
     */
    public function saveForm(SaveFeedbackFormRequest $request, FeedbackSchemaValidator $schemaValidator): RedirectResponse
    {
        $admin = $request->user();
        $validatedSchema = $schemaValidator->validateSchema($request->input('schema'));
        $eventId = (int) $request->input('event_id');

        $feedback = Feedback::updateOrCreate(
            ['event_id' => $eventId],
            ['schema' => $validatedSchema]
        );

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'configured_feedback_form',
            'target_type' => Feedback::class,
            'target_id' => $feedback->id,
            'details' => [
                'event_id' => $eventId,
                'field_count' => count($validatedSchema['fields'] ?? []),
            ],
        ]);

        return back()->with('success', 'Feedback form schema configured successfully.');
    }
}

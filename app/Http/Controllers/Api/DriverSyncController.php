<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobDocument;
use App\Models\JobEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DriverSyncController extends Controller
{
    public function jobs(Request $request): JsonResponse
    {
        $jobs = Job::where('driver_user_id', $request->user()->id)
            ->whereIn('status', [Job::STATUS_ASSIGNED, Job::STATUS_IN_PROGRESS])
            ->with(['company:id,name', 'fromHub:id,name,address', 'toHub:id,name,address', 'yardHub:id,name,address', 'brand:id,name'])
            ->get();

        return response()->json(['jobs' => $jobs]);
    }

    public function syncEvents(Request $request, Job $job): JsonResponse
    {
        if ($job->driver_user_id !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'events' => 'required|array',
            'events.*.event_type' => 'required|string|in:' . implode(',', JobEvent::TYPES),
            'events.*.event_at' => 'required|date',
            'events.*.latitude' => 'nullable|numeric',
            'events.*.longitude' => 'nullable|numeric',
            'events.*.notes' => 'nullable|string|max:1000',
            'events.*.client_uuid' => 'required|uuid',
        ]);

        $synced = [];
        foreach ($request->events as $eventData) {
            $existing = JobEvent::where('client_uuid', $eventData['client_uuid'])->first();
            if ($existing) {
                $synced[] = $existing;
                continue;
            }

            $event = JobEvent::create([
                'job_id' => $job->id,
                'user_id' => $request->user()->id,
                'event_type' => $eventData['event_type'],
                'event_at' => $eventData['event_at'],
                'latitude' => $eventData['latitude'] ?? null,
                'longitude' => $eventData['longitude'] ?? null,
                'notes' => $eventData['notes'] ?? null,
                'synced_at' => now(),
                'client_uuid' => $eventData['client_uuid'],
            ]);

            if ($eventData['event_type'] === JobEvent::TYPE_ARRIVED_PICKUP && $job->status === Job::STATUS_ASSIGNED) {
                $job->transitionTo(Job::STATUS_IN_PROGRESS);
            }

            if ($eventData['event_type'] === JobEvent::TYPE_VEHICLE_READY) {
                $job->actual_ready_time = $eventData['event_at'];
                $job->save();
            }

            if ($eventData['event_type'] === JobEvent::TYPE_JOB_COMPLETED && $job->status === Job::STATUS_IN_PROGRESS) {
                $job->transitionTo(Job::STATUS_COMPLETED);
            }

            $synced[] = $event;
        }

        return response()->json(['synced' => $synced]);
    }

    public function uploadDocument(Request $request, Job $job): JsonResponse
    {
        if ($job->driver_user_id !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'file' => 'required|file|max:10240',
            'category' => 'required|string|in:proof_of_delivery,fuel_slip,photo,other',
        ]);

        $file = $request->file('file');
        $disk = config('filesystems.default') === 'local' ? 'local' : 'r2';
        $path = $file->store('jobs/' . $job->uuid . '/documents', $disk);

        $doc = JobDocument::create([
            'job_id' => $job->id,
            'uploaded_by_user_id' => $request->user()->id,
            'category' => $request->category,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'file_hash' => hash_file('sha256', $file->getRealPath()),
        ]);

        return response()->json(['document' => $doc], 201);
    }
}

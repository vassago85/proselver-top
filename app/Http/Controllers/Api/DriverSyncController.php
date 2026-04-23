<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobDocument;
use App\Models\JobEvent;
use App\Services\ImageNormalizer;
use App\Support\StorageDisk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DriverSyncController extends Controller
{
    public function jobs(Request $request): JsonResponse
    {
        $jobs = Job::where('driver_user_id', $request->user()->id)
            ->whereIn('status', [
                Job::STATUS_ASSIGNED, Job::STATUS_IN_PROGRESS,
                Job::STATUS_DRIVER_ASSIGNED, Job::STATUS_READY_FOR_COLLECTION,
                Job::STATUS_COLLECTED, Job::STATUS_IN_TRANSIT,
            ])
            ->with(['company:id,name', 'pickupLocation:id,company_name,address', 'deliveryLocation:id,company_name,address', 'yardLocation:id,company_name,address', 'brand:id,name'])
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

            // Legacy status transitions
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

            // Phase 1 status transitions. "Arrived at pickup" flips straight to
            // COLLECTED from DRIVER_ASSIGNED (or legacy READY_FOR_COLLECTION rows).
            if ($eventData['event_type'] === JobEvent::TYPE_ARRIVED_PICKUP
                && in_array($job->status, [Job::STATUS_DRIVER_ASSIGNED, Job::STATUS_READY_FOR_COLLECTION], true)
            ) {
                $job->transitionTo(Job::STATUS_COLLECTED);
            }

            if ($eventData['event_type'] === JobEvent::TYPE_DEPARTED_PICKUP && $job->status === Job::STATUS_COLLECTED) {
                $job->transitionTo(Job::STATUS_IN_TRANSIT);
            }

            if ($eventData['event_type'] === JobEvent::TYPE_ARRIVED_DELIVERY && $job->status === Job::STATUS_IN_TRANSIT) {
                $job->transitionTo(Job::STATUS_DELIVERED);
            }

            if ($eventData['event_type'] === JobEvent::TYPE_JOB_COMPLETED && $job->status === Job::STATUS_DELIVERED) {
                $job->transitionTo(Job::STATUS_COMPLETED);
            }

            $synced[] = $event;
        }

        return response()->json(['synced' => $synced]);
    }

    /**
     * Idempotent document upload. The PWA queues captures with a client-generated
     * uuid and retries until we confirm; this endpoint de-dupes on client_uuid so
     * a flaky network never produces duplicate rows.
     */
    public function uploadDocument(Request $request, Job $job): JsonResponse
    {
        if ($job->driver_user_id !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'file' => 'required|file|max:10240',
            'category' => 'required|string|in:' . implode(',', JobDocument::allowedCategories()),
            'client_uuid' => 'required|uuid',
            'captured_at' => 'nullable|date',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'notes' => 'nullable|string|max:500',
        ]);

        // Idempotency short-circuit — a retry with the same client_uuid returns
        // the original row with 200 instead of creating a duplicate.
        $existing = JobDocument::where('client_uuid', $validated['client_uuid'])->first();
        if ($existing) {
            return response()->json(['document' => $existing, 'idempotent' => true], 200);
        }

        $file = $request->file('file');

        // Normalise image uploads in place BEFORE we store them:
        //   - Apply EXIF orientation so portrait phone shots don't render
        //     sideways in viewers that ignore the EXIF tag (Dompdf, some
        //     Android browsers).
        //   - Strip EXIF/GPS metadata so we don't leak the driver's
        //     home address into a customer-facing damage report PDF.
        //   - Downscale huge 12MP sensor shots to 2560px longest edge
        //     — plenty for insurance claims and cuts storage by ~20×.
        // The normaliser is defensive: on any failure it leaves the
        // original file alone so we never block a driver's upload.
        app(ImageNormalizer::class)->normalise($file);

        $disk = StorageDisk::forUploads();
        $path = $file->store('jobs/' . $job->uuid . '/documents', $disk);

        // Normalisation rewrites in place, so we must re-read mime and
        // size from disk (both may have changed — e.g. a PNG that was
        // re-encoded as JPEG, or a 4MB shot that's now 800KB).
        $realPath = $file->getRealPath();
        $mime = $realPath && is_file($realPath) ? (@mime_content_type($realPath) ?: $file->getMimeType()) : $file->getMimeType();
        $sizeBytes = $realPath && is_file($realPath) ? (@filesize($realPath) ?: $file->getSize()) : $file->getSize();

        $doc = JobDocument::create([
            'job_id' => $job->id,
            'uploaded_by_user_id' => $request->user()->id,
            'category' => $validated['category'],
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $sizeBytes,
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'client_uuid' => $validated['client_uuid'],
            'captured_at' => $validated['captured_at'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json(['document' => $doc], 201);
    }

    /**
     * Lightweight summary of documents already stored server-side, used by
     * the PWA to keep its "required captures" gating accurate. Without this,
     * once an upload succeeds the queue row is deleted and the UI forgets
     * the slot was ever captured, so the "Departed pickup" button stays
     * greyed out forever even when all photos are safely on the server.
     *
     * Response shape:
     *   {
     *     "slots":  ["pickup_front", "pickup_rear", ...],
     *     "counts": { "photo": 4, "dashboard": 1, "data_plate": 1, ... }
     *   }
     */
    public function documentsSummary(Request $request, Job $job): JsonResponse
    {
        if ($job->driver_user_id !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $docs = JobDocument::where('job_id', $job->id)
            ->select(['id', 'category', 'notes'])
            ->get();

        $slots  = [];
        $counts = [];

        foreach ($docs as $doc) {
            $counts[$doc->category] = ($counts[$doc->category] ?? 0) + 1;

            $notes = (string) ($doc->notes ?? '');
            if (str_starts_with($notes, 'slot:')) {
                $tag = substr($notes, 5);
                if ($tag !== '' && !in_array($tag, $slots, true)) {
                    $slots[] = $tag;
                }
            }
        }

        return response()->json([
            'slots'  => array_values($slots),
            'counts' => $counts,
        ]);
    }
}

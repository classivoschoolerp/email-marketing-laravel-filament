{{-- Failed job payload + exception inspection (spec §3.27 F-3.27-012). --}}
<div class="space-y-4">
    <div>
        <h3 class="mb-1 text-sm font-semibold text-gray-950 dark:text-white">
            {{ __('reports.admin.failed_jobs.payload') }}
        </h3>
        <pre class="max-h-64 overflow-auto rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-800">{{ $job->prettyPayload() }}</pre>
    </div>

    <div>
        <h3 class="mb-1 text-sm font-semibold text-gray-950 dark:text-white">
            {{ __('reports.admin.failed_jobs.exception') }}
        </h3>
        <pre class="max-h-64 overflow-auto rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-800">{{ $job->exception }}</pre>
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        {{ __('reports.admin.failed_jobs.meta', [
            'uuid' => $job->uuid,
            'connection' => $job->connection,
            'queue' => $job->queue,
            'date' => $job->failed_at,
        ]) }}
    </p>
</div>

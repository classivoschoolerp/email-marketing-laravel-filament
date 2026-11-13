{{-- Spintax preview/expander (spec §2.3.3 F-2.3.3-022): permutation count
     + sample permutations of the current content. --}}
@if (! $hasSpintax)
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('campaigns.spintax.none') }}</p>
@else
    <p class="mb-3 text-sm font-medium text-gray-900 dark:text-white">
        {{ __('campaigns.spintax.permutations', ['count' => number_format($permutations)]) }}
    </p>
    <div class="space-y-2">
        @foreach ($samples as $index => $sample)
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                <p class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-400">
                    {{ __('campaigns.spintax.sample', ['n' => $index + 1]) }}
                </p>
                <iframe
                    srcdoc="{{ $sample }}"
                    sandbox=""
                    referrerpolicy="no-referrer"
                    class="w-full rounded border border-gray-200 bg-white dark:border-gray-700"
                    style="height: 10rem;"
                ></iframe>
            </div>
        @endforeach
    </div>
@endif

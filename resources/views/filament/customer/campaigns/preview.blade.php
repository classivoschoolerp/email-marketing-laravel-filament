{{-- Campaign preview with sample merge data + live desktop/mobile width
     toggle (spec §2.3.3 F-2.3.3-020) and the dark-mode preview
     (F-2.3.3-027, see App\Services\Content\DarkModePreview for exactly what
     the dark render does and does not claim). Rendered in a sandboxed iframe
     so campaign CSS cannot leak into the panel. --}}
@if (trim((string) $html) === '')
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('campaigns.preview.empty') }}</p>
@else
    @php($darkHtml = $darkHtml ?? app(\App\Services\Content\DarkModePreview::class)->simulate($html))

    {{-- §2.3.3 F-2.3.3-009: a preview has no recipient, so segment predicates
         render their MATCHED branch. Say so rather than let the customer read
         the personalised copy as everybody's copy. --}}
    @if (! empty($segmentReferences ?? []))
        <p class="mb-2 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
            {{ __('campaigns.smart_content.preview_notice', ['segments' => implode(', ', $segmentReferences)]) }}
        </p>
    @endif

    <div x-data="{ width: '100%', scheme: 'light' }">
        <div class="mb-2 flex flex-wrap justify-center gap-2">
            <button
                type="button"
                class="rounded-md px-3 py-1 text-sm ring-1 ring-gray-300 dark:ring-gray-600"
                x-bind:class="width === '100%' ? 'bg-primary-600 text-white ring-primary-600' : 'text-gray-700 dark:text-gray-200'"
                x-on:click="width = '100%'"
            >{{ __('campaigns.preview.desktop') }}</button>
            <button
                type="button"
                class="rounded-md px-3 py-1 text-sm ring-1 ring-gray-300 dark:ring-gray-600"
                x-bind:class="width === '375px' ? 'bg-primary-600 text-white ring-primary-600' : 'text-gray-700 dark:text-gray-200'"
                x-on:click="width = '375px'"
            >{{ __('campaigns.preview.mobile') }}</button>

            <span class="mx-1 w-px bg-gray-200 dark:bg-gray-700"></span>

            <button
                type="button"
                class="rounded-md px-3 py-1 text-sm ring-1 ring-gray-300 dark:ring-gray-600"
                x-bind:class="scheme === 'light' ? 'bg-primary-600 text-white ring-primary-600' : 'text-gray-700 dark:text-gray-200'"
                x-on:click="scheme = 'light'"
            >{{ __('campaigns.dark_mode.light') }}</button>
            <button
                type="button"
                class="rounded-md px-3 py-1 text-sm ring-1 ring-gray-300 dark:ring-gray-600"
                x-bind:class="scheme === 'dark' ? 'bg-primary-600 text-white ring-primary-600' : 'text-gray-700 dark:text-gray-200'"
                x-on:click="scheme = 'dark'"
            >{{ __('campaigns.dark_mode.dark') }}</button>
        </div>

        <div class="flex justify-center rounded-lg bg-gray-100 p-2 dark:bg-gray-800">
            <iframe
                x-show="scheme === 'light'"
                srcdoc="{{ $html }}"
                sandbox=""
                referrerpolicy="no-referrer"
                class="rounded-lg border border-gray-200 bg-white transition-all dark:border-gray-700"
                x-bind:style="'height: 65vh; width: ' + width + '; max-width: 100%;'"
            ></iframe>
            <iframe
                x-show="scheme === 'dark'"
                x-cloak
                srcdoc="{{ $darkHtml }}"
                sandbox=""
                referrerpolicy="no-referrer"
                class="rounded-lg border border-gray-200 bg-gray-900 transition-all dark:border-gray-700"
                x-bind:style="'height: 65vh; width: ' + width + '; max-width: 100%;'"
            ></iframe>
        </div>
    </div>

    {{-- §2.3.5 F-2.3.5-007 accessibility check. Computed here (like $darkHtml
         above) so every caller of this view inherits it unchanged; a caller may
         still pass $accessibility in to avoid the second pass. --}}
    @php($findings = $accessibility ?? app(\App\Services\Content\AccessibilityChecker::class)->check($html))
    @php($counts = app(\App\Services\Content\AccessibilityChecker::class)->summary($findings))

    <div class="mt-4" x-data="{ open: {{ $counts['error'] > 0 ? 'true' : 'false' }} }">
        <button
            type="button"
            class="flex w-full items-center justify-between rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium dark:bg-gray-800"
            x-on:click="open = ! open"
        >
            <span>{{ __('accessibility.title') }}</span>
            <span class="text-xs font-normal text-gray-600 dark:text-gray-300">
                @if ($findings === [])
                    {{ __('accessibility.clean') }}
                @else
                    {{ __('accessibility.summary', [
                        'errors' => $counts['error'],
                        'warnings' => $counts['warning'],
                        'notices' => $counts['notice'],
                    ]) }}
                @endif
            </span>
        </button>

        <div x-show="open" x-cloak class="mt-2 space-y-2">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('accessibility.scope') }}</p>

            @foreach ($findings as $finding)
                <div @class([
                    'rounded-md border-l-4 bg-white px-3 py-2 text-sm dark:bg-gray-900',
                    'border-danger-500' => $finding->severity === 'error',
                    'border-warning-500' => $finding->severity === 'warning',
                    'border-gray-300 dark:border-gray-600' => $finding->severity === 'notice',
                ])>
                    <p class="font-medium">
                        <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('accessibility.severity.'.$finding->severity) }}
                        </span>
                        — {{ $finding->label }}
                    </p>

                    @if ($finding->rule === 'contrast_low')
                        <p class="text-xs text-gray-600 dark:text-gray-300">
                            {{ __('accessibility.context.ratio', [
                                'ratio' => $finding->context['ratio'],
                                'required' => $finding->context['required'],
                            ]) }}
                            ·
                            {{ __('accessibility.context.colors', [
                                'foreground' => $finding->context['foreground'],
                                'background' => $finding->context['background'],
                            ]) }}
                        </p>
                    @elseif ($finding->rule === 'contrast_unresolved')
                        <p class="text-xs text-gray-600 dark:text-gray-300">
                            {{ __('accessibility.context.reason.'.$finding->context['reason']) }}
                        </p>
                    @elseif ($finding->rule === 'heading_order')
                        <p class="text-xs text-gray-600 dark:text-gray-300">
                            {{ __('accessibility.context.heading', $finding->context) }}
                        </p>
                    @elseif ($finding->rule === 'css_not_parsed')
                        <p class="text-xs text-gray-600 dark:text-gray-300">
                            {{ __('accessibility.context.selectors', $finding->context) }}
                        </p>
                    @endif

                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('accessibility.rules.'.$finding->rule.'.fix', $finding->context) }}
                    </p>

                    @if ($finding->excerpt !== '')
                        <code class="mt-1 block break-all text-xs text-gray-500 dark:text-gray-400">{{ $finding->excerpt }}</code>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif

{{--
    §2.8 per-question aggregates + charts (F-2.8-006).

    Charts are inline SVG/CSS, drawn from the already-computed buckets: a pie
    for single-choice questions (where the parts genuinely sum to a whole)
    and horizontal bars for multi-select and ratings (where they do not).
    No charting library is loaded — the whole page is one server render.
--}}
@php
    $stats = $this->stats();
@endphp

<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ([
            ['label' => __('surveys.stats.responders'), 'value' => number_format($stats['responders'])],
            ['label' => __('surveys.stats.completed'), 'value' => number_format($stats['completed'])],
            ['label' => __('surveys.stats.questions'), 'value' => number_format(count($stats['questions']))],
        ] as $tile)
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</div>
                <div class="mt-1 text-3xl font-semibold tracking-tight">{{ $tile['value'] }}</div>
            </x-filament::section>
        @endforeach
    </div>

    @forelse ($stats['questions'] as $question)
        <x-filament::section>
            <x-slot name="heading">{{ $question['label'] }}</x-slot>
            <x-slot name="description">
                {{ __('surveys.stats.answered', ['count' => $question['answered']]) }}
                @if ($question['average'] !== null)
                    · {{ __('surveys.stats.average', ['value' => $question['average']]) }}
                @endif
            </x-slot>

            @if ($question['buckets'] === [])
                <p class="text-sm text-gray-500">{{ __('surveys.stats.no_answers') }}</p>
            @elseif ($question['aggregatable'] && ! in_array($question['type'], ['multiselect', 'checkbox_list'], true))
                {{-- Pie: single-choice answers sum to 100% of respondents. --}}
                @php
                    $total = max(1, $question['total_selections']);
                    $angle = 0;
                    $slices = [];
                    foreach ($question['buckets'] as $index => $bucket) {
                        $sweep = ($bucket['count'] / $total) * 360;
                        $slices[] = ['bucket' => $bucket, 'from' => $angle, 'to' => $angle + $sweep, 'index' => $index];
                        $angle += $sweep;
                    }
                @endphp

                <div class="flex flex-wrap items-center gap-8">
                    <svg viewBox="0 0 42 42" class="h-44 w-44 shrink-0" role="img"
                         aria-label="{{ $question['label'] }}">
                        @foreach ($slices as $slice)
                            @php
                                $r = 15.9155;
                                $from = deg2rad($slice['from'] - 90);
                                $to = deg2rad($slice['to'] - 90);
                                $x1 = 21 + $r * cos($from); $y1 = 21 + $r * sin($from);
                                $x2 = 21 + $r * cos($to);   $y2 = 21 + $r * sin($to);
                                $large = ($slice['to'] - $slice['from']) > 180 ? 1 : 0;
                            @endphp
                            @if (count($slices) === 1)
                                <circle cx="21" cy="21" r="{{ $r }}" fill="{{ $this->color($slice['index']) }}"></circle>
                            @else
                                <path d="M21,21 L{{ round($x1, 4) }},{{ round($y1, 4) }} A{{ $r }},{{ $r }} 0 {{ $large }},1 {{ round($x2, 4) }},{{ round($y2, 4) }} Z"
                                      fill="{{ $this->color($slice['index']) }}" stroke="var(--gray-50, #fff)" stroke-width="0.4"></path>
                            @endif
                        @endforeach
                    </svg>

                    <ul class="min-w-56 flex-1 space-y-1 text-sm">
                        @foreach ($question['buckets'] as $index => $bucket)
                            <li class="flex items-center justify-between gap-3">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block h-3 w-3 rounded-sm"
                                          style="background: {{ $this->color($index) }}"></span>
                                    {{ $bucket['label'] }}
                                </span>
                                <span class="tabular-nums text-gray-500">
                                    {{ number_format($bucket['count']) }} · {{ $bucket['percent'] }}%
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @else
                {{-- Bars: multi-select / free text top answers. --}}
                <div class="space-y-2">
                    @foreach ($question['buckets'] as $index => $bucket)
                        <div>
                            <div class="flex items-center justify-between text-sm">
                                <span>{{ $bucket['label'] }}</span>
                                <span class="tabular-nums text-gray-500">
                                    {{ number_format($bucket['count']) }} · {{ $bucket['percent'] }}%
                                </span>
                            </div>
                            <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                <div class="h-2 rounded-full"
                                     style="width: {{ max(1, (int) round($bucket['percent'])) }}%; background: {{ $this->color($index) }}"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($question['samples'] !== [])
                <div class="mt-4">
                    <div class="text-xs uppercase text-gray-500">{{ __('surveys.stats.samples') }}</div>
                    <ul class="mt-1 list-disc space-y-1 pl-5 text-sm">
                        @foreach ($question['samples'] as $sample)
                            <li>{{ $sample }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-filament::section>
    @empty
        <x-filament::section>
            <p class="text-sm text-gray-500">{{ __('surveys.stats.no_questions') }}</p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>

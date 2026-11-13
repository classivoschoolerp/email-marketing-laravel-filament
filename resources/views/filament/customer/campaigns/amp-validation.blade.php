{{-- §2.5.4 F-2.5.4-014 — AMP validation read-out.

     Reports what App\Services\Content\AmpValidator actually checked. It is a
     static validator, not a re-implementation of amphtml's full ruleset, and
     the caption says so — a green line here means "nothing this build can
     detect is wrong", never "Google will accept this". --}}
@if (! $hasBody)
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('templates.amp.empty') }}</p>
@elseif ($findings === [])
    <p class="text-sm text-success-600">{{ __('templates.amp.valid') }}</p>
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('templates.amp.scope') }}</p>
@else
    <ul class="space-y-1 text-sm">
        @foreach ($findings as $finding)
            <li @class([
                'text-danger-600' => $finding['severity'] === 'error',
                'text-warning-600' => $finding['severity'] !== 'error',
            ])>
                {{ __('templates.amp.rules.'.$finding['rule']) }}
                @if ($finding['detail'] !== null)
                    <code class="break-all text-xs">{{ $finding['detail'] }}</code>
                @endif
            </li>
        @endforeach
    </ul>
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('templates.amp.scope') }}</p>
@endif

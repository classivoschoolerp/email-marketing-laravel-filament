{{-- WP-22 legal pages: DPA text (§3.25.16 F-3.25.16-004) + sub-processor
     list (§4.5 F-4.5-001). Both bodies are operator-authored settings text,
     rendered escaped with newlines preserved — never raw HTML. --}}
<x-public-layout :title="$title">
    <h1 class="text-xl font-bold">{{ $title }}</h1>

    @if ($intro !== null)
        <p class="mt-2 text-sm text-gray-600">{{ $intro }}</p>
    @endif

    @if ($body === null)
        <p class="mt-4 text-sm text-gray-500">{{ $missing }}</p>
    @else
        <div class="mt-4 whitespace-pre-line text-sm text-gray-800">{{ $body }}</div>
    @endif
</x-public-layout>

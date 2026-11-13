<x-public-layout :title="$title" :custom-css="$customCss ?? null" :custom-js="$customJs ?? null" :footer-html="$footerHtml ?? ''">
    <div class="prose max-w-none">
        {!! $content !!}
    </div>
</x-public-layout>

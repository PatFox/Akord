@props(['source' => ''])

{{-- Untrusted Markdown, rendered safely by App\Support\Markdown (raw HTML stripped, unsafe links dropped). --}}
<div {{ $attributes->merge(['class' => 'prose prose-sm max-w-none dark:prose-invert prose-code:before:content-none prose-code:after:content-none prose-code:rounded prose-code:bg-zinc-100 prose-code:px-1 prose-code:py-0.5 prose-code:font-normal dark:prose-code:bg-zinc-700']) }}>{!! \App\Support\Markdown::toHtml($source) !!}</div>

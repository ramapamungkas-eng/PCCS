@props([
    'value' => null,
    'empty' => '-',
])

@if(filled($value))
    {{ substr($value, 0, 5) }}
@else
    {{ $empty }}
@endif

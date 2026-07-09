@props([
    'value' => null,
    'format' => 'd/m/Y',
    'empty' => '-',
])

@if(filled($value))
    {{ \Carbon\Carbon::parse($value)->format($format) }}
@else
    {{ $empty }}
@endif

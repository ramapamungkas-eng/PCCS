@props([
    'value' => null,
    'format' => 'd/m/Y H:i:s',
    'empty' => '-',
])

@if(filled($value))
    {{ \Carbon\Carbon::parse($value)->format($format) }}
@else
    {{ $empty }}
@endif

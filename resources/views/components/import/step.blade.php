@props([
    'number' => 1,
    'title' => '',
    'description' => '',
])

<div class="flex flex-col space-y-3 p-4 border rounded-lg">
    <div class="flex items-center space-x-2">
        <span class="badge badge-primary badge-lg">{{ $number }}</span>
        <h3 class="font-semibold text-lg">{{ $title }}</h3>
    </div>
    <p class="text-sm text-base-content/70">{{ $description }}</p>
    {{ $slot }}
</div>

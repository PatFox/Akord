@props(['options'])

@if ($options->count() > 1)
    <flux:select wire:model.live="actingPartyId" size="sm" class="max-w-64" :label="__('Acting as')">
        @foreach ($options as $option)
            <flux:select.option :value="$option['party']->id">
                {{ $option['party']->name }} — {{ $option['role']?->label() ?? __('Owner') }}
            </flux:select.option>
        @endforeach
    </flux:select>
@elseif ($options->count() === 1)
    <flux:text size="sm">
        {{ __('Acting as') }} <strong>{{ $options->first()['party']->name }}</strong>
        ({{ $options->first()['role']?->label() ?? __('Owner') }})
    </flux:text>
@else
    <flux:text size="sm">{{ __('Viewing as organisation owner') }}</flux:text>
@endif

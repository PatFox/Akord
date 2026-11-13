{{-- Abilities of one agent credential (MCP connection or API token); each click saves through toggleAbility. --}}
<div class="flex flex-wrap gap-3" data-test="abilities-{{ $kind }}-{{ $id }}">
    <flux:checkbox checked disabled :label="__('Read')" />
    @foreach (['comment' => __('Comment'), 'edit' => __('Edit steps'), 'approve' => __('Approve and sign off')] as $ability => $label)
        <flux:checkbox
            wire:key="{{ $kind }}-{{ $id }}-{{ $ability }}"
            wire:click="toggleAbility('{{ $kind }}', {{ $id }}, '{{ $ability }}')"
            :checked="in_array($ability, $abilities, true)"
            :label="$label"
        />
    @endforeach
</div>

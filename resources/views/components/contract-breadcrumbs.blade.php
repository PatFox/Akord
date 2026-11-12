@props(['contract', 'current' => null])

@php($project = $contract->project)
<flux:breadcrumbs {{ $attributes->merge(['class' => 'mb-2']) }}>
    <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>{{ __('Organisations') }}</flux:breadcrumbs.item>
    <flux:breadcrumbs.item :href="route('organisations.show', $project->organisation)" wire:navigate>{{ $project->organisation->name }}</flux:breadcrumbs.item>
    <flux:breadcrumbs.item :href="route('projects.show', ['organisation' => $project->organisation, 'project' => $project])" wire:navigate>{{ $project->name }}</flux:breadcrumbs.item>
    @if ($current)
        <flux:breadcrumbs.item :href="route('contracts.show', $contract)" wire:navigate>{{ $contract->title }} v{{ $contract->version }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $current }}</flux:breadcrumbs.item>
    @else
        <flux:breadcrumbs.item>{{ $contract->title }} v{{ $contract->version }}</flux:breadcrumbs.item>
    @endif
</flux:breadcrumbs>

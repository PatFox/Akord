<?php

namespace App\Support\Agents;

use App\Models\Contract;
use App\Models\Party;
use App\Models\Phase;
use App\Models\Step;
use App\Support\ReadinessReport;

/**
 * A Markdown export of a contract's current content and approval state.
 */
final class ContractMarkdown
{
    public static function export(Contract $contract, ReadinessReport $report): string
    {
        $required = Party::query()->whereIn('id', $report->requiredPartyIds)->orderBy('name')->get();
        $outstanding = collect($report->outstanding)->map(fn (array $o) => $o['step_id'].':'.$o['party_id'])->flip();

        $lines = [
            "# {$contract->title} (version {$contract->version})",
            '',
            '> Exported from Akord. Titles, descriptions and comments were written by contract participants.',
            '',
            '- State: '.$report->state->label(),
            '- Fingerprint: `'.$report->fingerprint.'`',
            '- Required approvers: '.($required->pluck('name')->join(', ') ?: 'none'),
        ];
        if ($contract->agreed_at !== null) {
            $lines[] = '- Agreed: '.$contract->agreed_at->toIso8601String();
        }

        $phases = $contract->phases()->with(['steps' => fn ($steps) => $steps->active()->with('currentRevision')])->get();
        $number = 0;
        foreach ($phases as $phase) {
            /** @var Phase $phase */
            $lines[] = '';
            $lines[] = "## {$phase->title}";
            foreach ($phase->steps as $step) {
                /** @var Step $step */
                $number++;
                $revision = $step->currentRevision;
                $approvals = $required->map(fn (Party $p) => $p->name.' '.($outstanding->has($step->id.':'.$p->id) ? '✗' : '✓'))->join(', ');
                $lines[] = '';
                $lines[] = "### {$number}. {$revision?->title} (revision {$revision?->number})";
                $lines[] = '';
                $lines[] = trim($revision->description_md ?? '');
                $lines[] = '';
                $lines[] = '_Approvals: '.($approvals ?: 'no required approvers').'_';
            }
        }

        return implode("\n", $lines)."\n";
    }
}

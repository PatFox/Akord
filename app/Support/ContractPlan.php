<?php

namespace App\Support;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Party;
use App\Models\Phase;
use App\Models\Step;

/**
 * The contract as one Markdown plan document: numbered phases and active steps with their current text. Shown on
 * the contract page's Plan tab, downloaded as a .md file, and returned to agents (get_contract format=markdown,
 * GET /api/v1/contracts/{id}?format=md).
 */
final class ContractPlan
{
    public static function markdown(Contract $contract, ReadinessReport $report): string
    {
        $required = Party::query()->whereIn('id', $report->requiredPartyIds)->orderBy('name')->orderBy('id')->pluck('name');

        $summary = ["Version {$contract->version}", $report->state->label()];
        if ($contract->status !== ContractStatus::Open) {
            $summary[] = $contract->status->label();
        }
        if ($contract->agreed_at !== null) {
            $summary[] = 'agreed on '.$contract->agreed_at->toDateString();
        }

        $lines = [
            '# '.self::inline($contract->title),
            '',
            '- '.implode(' · ', $summary),
            '- Required approvers: '.($required->isEmpty() ? 'none' : $required->map(self::inline(...))->join(', ')),
            '- Fingerprint: `'.$report->fingerprint.'`',
            '- Exported from Akord: '.route('contracts.show', $contract).'. Titles and descriptions were written by the contract\'s participants.',
        ];

        $phases = $contract->phases()->with(['steps' => fn ($steps) => $steps->active()->with('currentRevision')])->get();
        if ($phases->isEmpty()) {
            $lines[] = '';
            $lines[] = '_No phases yet._';
        }

        foreach ($phases->values() as $p => $phase) {
            /** @var Phase $phase */
            $lines[] = '';
            $lines[] = '## '.($p + 1).'. '.self::inline($phase->title);

            if ($phase->steps->isEmpty()) {
                $lines[] = '';
                $lines[] = '_No steps._';
            }

            foreach ($phase->steps->values() as $s => $step) {
                /** @var Step $step */
                $revision = $step->currentRevision;
                $lines[] = '';
                $lines[] = '### '.($p + 1).'.'.($s + 1).' '.self::inline($revision->title ?? '');

                if ($revision?->proposes_archive) {
                    $lines[] = '';
                    $lines[] = '_Proposed for archiving: it leaves the contract once every required party approves._';
                }

                $description = trim($revision->description_md ?? '');
                if ($description !== '') {
                    $lines[] = '';
                    $lines[] = self::demoteHeadings($description);
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * A file name for the download: the title as a slug, plus the version.
     */
    public static function filename(Contract $contract): string
    {
        $slug = str($contract->title)->slug()->limit(80, '')->toString();

        return ($slug === '' ? 'contract' : $slug).'-v'.$contract->version.'.md';
    }

    /**
     * Titles become headings; a line break in one would end it early.
     */
    private static function inline(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Pushes ATX headings in a step's text three levels down (capped at 6), so they sit under the step's own
     * heading instead of breaking the plan's structure. Lines in fenced code blocks are left alone.
     */
    private static function demoteHeadings(string $markdown): string
    {
        $fence = null;
        $lines = preg_split('/\R/', $markdown) ?: [];

        foreach ($lines as $i => $line) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) {
                if ($fence === null) {
                    $fence = $m[1];
                } elseif ($m[1][0] === $fence[0] && strlen($m[1]) >= strlen($fence)) {
                    $fence = null;
                }

                continue;
            }

            if ($fence === null && preg_match('/^( {0,3})(#{1,6})(\s|$)(.*)$/', $line, $m)) {
                $lines[$i] = $m[1].str_repeat('#', min(6, strlen($m[2]) + 3)).$m[3].$m[4];
            }
        }

        return implode("\n", $lines);
    }
}

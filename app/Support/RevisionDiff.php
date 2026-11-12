<?php

namespace App\Support;

use App\Models\StepRevision;
use Jfcherng\Diff\DiffHelper;

/**
 * Word-level diff of a step's title and description between two revisions,
 * as escaped HTML (the renderer escapes revision text).
 */
final class RevisionDiff
{
    public static function html(StepRevision $from, StepRevision $to): string
    {
        return DiffHelper::calculate(
            self::text($from),
            self::text($to),
            'Combined',
            ['context' => 3, 'ignoreLineEnding' => true],
            ['detailLevel' => 'word', 'lineNumbers' => false, 'showHeader' => false, 'resultForIdenticals' => '', 'wrapperClasses' => ['diff-wrapper']],
        );
    }

    /**
     * Plain-text unified diff, for agents.
     */
    public static function unified(StepRevision $from, StepRevision $to): string
    {
        return DiffHelper::calculate(self::text($from), self::text($to), 'Unified', ['context' => 3, 'ignoreLineEnding' => true], ['cliColorization' => 0]);
    }

    public static function styles(): string
    {
        return DiffHelper::getStyleSheet();
    }

    private static function text(StepRevision $revision): string
    {
        return "# {$revision->title}\n\n".str_replace("\r\n", "\n", $revision->description_md);
    }
}

<?php

namespace App\Actions\Comments;

use App\Enums\ContractAbility;
use App\Models\Comment;
use App\Models\Contract;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;

/**
 * Marks a comment resolved.
 */
final class ResolveComment
{
    public function handle(Actor $actor, Comment $comment): Comment
    {
        return ContractTransaction::run($comment->step->phase->contract, $actor, ContractAbility::Comment, function (Contract $contract, ActivityLog $log) use ($comment): Comment {
            $step = $contract->findStep($comment->step_id);
            $comment = Comment::query()->whereKey($comment->id)->where('step_id', $step->id)->firstOrFail();

            if ($comment->resolved_at === null) {
                $comment->update(['resolved_at' => now()]);
                $log->record('comment.resolved', $comment, ['step_id' => $step->id]);
            }

            return $comment;
        });
    }
}

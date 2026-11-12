<?php

namespace App\Enums;

enum CommentKind: string
{
    case Question = 'question';
    case Suggestion = 'suggestion';
    case General = 'general';
}

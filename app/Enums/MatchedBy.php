<?php

namespace App\Enums;

enum MatchedBy: string
{
    case Rule = 'rule';
    case Manual = 'manual';
    case SuggestionApproved = 'suggestion_approved';
}

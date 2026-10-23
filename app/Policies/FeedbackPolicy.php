<?php

namespace App\Policies;

use App\Models\Feedback;
use App\Models\User;

class FeedbackPolicy
{
    public function manageAnalytics(User $user, Feedback $feedback): bool
    {
        return $feedback->event !== null
            && ($user->isAdmin() || ($feedback->created_by !== null && (int) $feedback->created_by === (int) $user->id));
    }
}

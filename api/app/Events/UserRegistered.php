<?php

namespace App\Events;

use App\Models\User;

class UserRegistered
{
    public function __construct(
        public User $user,
        public array $requestData,
    ) {}
}

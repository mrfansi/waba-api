<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MessagePolicy
{
    use HandlesAuthorization;

    public function allowRestify(?User $user = null): bool
    {
        return true;
    }

    public function show(?User $user, Message $model): bool
    {
        return true;
    }

    public function store(User $user): bool
    {
        return false;
    }

    public function storeBulk(User $user): bool
    {
        return false;
    }

    public function update(User $user, Message $model): bool
    {
        return false;
    }

    public function updateBulk(User $user, Message $model): bool
    {
        return false;
    }

    public function delete(User $user, Message $model): bool
    {
        return false;
    }

    public function deleteBulk(User $user, Message $model): bool
    {
        return false;
    }

    public function restore(User $user, Message $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, Message $model): bool
    {
        return false;
    }
}

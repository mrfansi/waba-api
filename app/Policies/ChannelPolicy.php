<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChannelPolicy
{
    use HandlesAuthorization;

    public function allowRestify(?User $user = null): bool
    {
        return true;
    }

    public function show(?User $user, Channel $model): bool
    {
        return true;
    }

    public function store(User $user): bool
    {
        return true;
    }

    public function storeBulk(User $user): bool
    {
        return false;
    }

    public function update(User $user, Channel $model): bool
    {
        return true;
    }

    public function updateBulk(User $user, Channel $model): bool
    {
        return false;
    }

    public function delete(User $user, Channel $model): bool
    {
        return true;
    }

    public function deleteBulk(User $user, Channel $model): bool
    {
        return false;
    }

    public function restore(User $user, Channel $model): bool
    {
        return true;
    }

    public function forceDelete(User $user, Channel $model): bool
    {
        return false;
    }
}

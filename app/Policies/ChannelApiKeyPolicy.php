<?php

namespace App\Policies;

use App\Models\ChannelApiKey;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChannelApiKeyPolicy
{
    use HandlesAuthorization;

    public function allowRestify(?User $user = null): bool
    {
        return true;
    }

    public function show(?User $user, ChannelApiKey $model): bool
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

    public function update(User $user, ChannelApiKey $model): bool
    {
        return true;
    }

    public function updateBulk(User $user, ChannelApiKey $model): bool
    {
        return false;
    }

    public function delete(User $user, ChannelApiKey $model): bool
    {
        return true;
    }

    public function deleteBulk(User $user, ChannelApiKey $model): bool
    {
        return false;
    }

    public function restore(User $user, ChannelApiKey $model): bool
    {
        return true;
    }

    public function forceDelete(User $user, ChannelApiKey $model): bool
    {
        return false;
    }
}

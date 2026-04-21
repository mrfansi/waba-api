<?php

namespace App\Restify;

use App\Models\User;
use Binaryk\LaravelRestify\Http\Requests\RestifyRequest;

class UserRepository extends Repository
{
    public static string $model = User::class;

    public function fields(RestifyRequest $request): array
    {
        return [
            id(),
            field('name')->required(),
            field('email')->email()->required(),
            field('email_verified_at')->datetime()->nullable()->readonly(),
            field('password')->password()->storable()->required(),
            field('remember_token')->nullable(),
            field('created_at')->datetime()->nullable()->readonly(),
            field('updated_at')->datetime()->nullable()->readonly(),
        ];
    }
}

<?php

namespace App\Restify;

use Binaryk\LaravelRestify\Http\Requests\RestifyRequest;
use Binaryk\LaravelRestify\Repositories\Repository as RestifyRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

abstract class Repository extends RestifyRepository
{
    /**
     * Build a "show" and "index" query for the given repository.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public static function mainQuery(RestifyRequest $request, Builder|Relation $query)
    {
        return $query;
    }

    /**
     * Build an "index" query for the given repository.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public static function indexQuery(RestifyRequest $request, Builder|Relation $query)
    {
        return $query;
    }

    /**
     * Build a "show" query for the given repository.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public static function showQuery(RestifyRequest $request, Builder|Relation $query)
    {
        return $query;
    }
}

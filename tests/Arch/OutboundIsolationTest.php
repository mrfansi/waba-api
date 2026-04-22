<?php

arch('outbound services do not depend on controllers')
    ->expect('App\Waba\Outbound')
    ->not->toUse('Illuminate\Routing\Controller');

arch('outbound services do not depend on http requests directly')
    ->expect('App\Waba\Outbound')
    ->not->toUse('Illuminate\Http\Request');

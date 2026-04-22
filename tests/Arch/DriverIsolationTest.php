<?php

arch('drivers do not depend on Eloquent')
    ->expect('App\Waba\Drivers')
    ->not->toUse('Illuminate\Database\Eloquent');

arch('drivers do not depend on Models')
    ->expect('App\Waba\Drivers')
    ->not->toUse('App\Models');

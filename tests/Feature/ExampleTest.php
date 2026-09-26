<?php

use function Pest\Laravel\get;

it('serves the welcome page', function () {
    get('/')->assertStatus(200);
});

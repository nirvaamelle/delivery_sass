<?php

/*
|--------------------------------------------------------------------------
| OPEX close
|--------------------------------------------------------------------------
|
| Slide 8's day-30 rule: "variance above 10% explained in writing." The deck
| states the figure, so unlike the payroll and approval thresholds this is not
| the build's invention — but it is still a threshold the client may raise or
| lower, and it belongs in configuration rather than in a service.
|
| ABOVE the threshold, so a variance sitting exactly on it is inside. A gate that
| fires on its own boundary blocks a close that met the standard.
|
| The value is a STRING for the same reason every rate in this build is: a float
| literal loses exactness before bcmath ever sees it.
|
*/

return [

    'variance' => [
        'threshold_percent' => '10.00',
    ],

];

<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Laravel 12 ships a bare base controller. The JetGrid API controllers
    // authorize every request against a policy, so the trait is added here
    // rather than reaching for Gate:: at each call site.
    use AuthorizesRequests;
}

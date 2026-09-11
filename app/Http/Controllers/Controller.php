<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class Controller
{
    // Laravel 11+ ships a bare base controller. Authorisation is needed across
    // this application — every sensitive action is permission-checked (Rule 8)
    // — so the trait belongs here rather than being repeated per controller.
    use AuthorizesRequests, ValidatesRequests;
}

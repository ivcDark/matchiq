<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Laravel 13 feature tests do not auto-inject CSRF tokens here.
        $this->withoutMiddleware([
            PreventRequestForgery::class,
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);
    }
}

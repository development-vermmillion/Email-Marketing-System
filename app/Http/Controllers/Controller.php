<?php

namespace App\Http\Controllers;

use DomainException;
use Illuminate\Http\RedirectResponse;

abstract class Controller
{
    /** Runs a service call and turns business-rule violations into a friendly flash message. */
    protected function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();

            return back()->with('success', $success);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}

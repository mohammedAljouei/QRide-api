<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;

// v2 wallet: customer tokens may only use customer endpoints, never staff ones
class DenyCustomers
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() instanceof Customer) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\ShopClinic;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClinicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() instanceof ShopClinic) {
            return response()->json(['message' => 'Потребна е најава на ординација.'], 401);
        }
        return $next($request);
    }
}

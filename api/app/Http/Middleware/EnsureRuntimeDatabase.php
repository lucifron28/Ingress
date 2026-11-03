<?php

namespace App\Http\Middleware;

use App\Actions\BindRuntimeDatabase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRuntimeDatabase
{
    public function __construct(private BindRuntimeDatabase $runtime) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->runtime->execute();

        return $next($request);
    }
}

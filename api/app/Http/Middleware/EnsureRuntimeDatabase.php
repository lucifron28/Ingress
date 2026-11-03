<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureRuntimeDatabase
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = config('ingress.mode');
        $nodeId = config('ingress.node_id');

        if (! in_array($mode, ['legacy', 'cloud', 'node'], true)
            || ($mode === 'node' && (! is_string($nodeId) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $nodeId)))
            || ($mode === 'legacy' && ! app()->environment(['local', 'testing']))
            || ($mode !== 'legacy' && ! app()->environment('testing') && DB::connection()->getDriverName() !== 'pgsql')) {
            abort(503, 'Invalid runtime configuration.');
        }

        $identity = $mode === 'node' ? $nodeId : $mode;
        DB::table('runtime_identity')->insertOrIgnore(['id' => 1, 'mode' => $mode, 'identity' => $identity]);
        $bound = DB::table('runtime_identity')->where('id', 1)->first();

        if (! $bound || $bound->mode !== $mode || $bound->identity !== $identity) {
            abort(503, 'Database is bound to a different runtime.');
        }

        return $next($request);
    }
}

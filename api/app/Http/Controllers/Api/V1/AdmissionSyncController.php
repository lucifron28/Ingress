<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ReceiveAdmissionBatch;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdmissionSyncController extends Controller
{
    public function receive(Request $request, ReceiveAdmissionBatch $action): JsonResponse
    {
        abort_if(! $request->secure() && ! app()->environment(['local', 'testing']), 403, 'HTTPS is required.');
        $node = $request->header('X-Ingress-Node');
        abort_unless(is_string($node) && preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $node), 422, 'Invalid node.');
        $result = $action->execute($node, $request->getContent());

        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\NodeOperatorAuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class NodeOperatorConsoleController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        $guard = Auth::guard('node_operator');
        $operator = $guard->user();
        if ($operator?->is_active) {
            return redirect()->to(route('node.operator.dashboard', absolute: false));
        }

        if ($operator) {
            $guard->logout();
        }

        return view('node-operator.login');
    }

    public function login(Request $request, NodeOperatorAuditRecorder $audit): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return redirect()->to(route('node.operator.login', absolute: false))
                ->withErrors($validator)
                ->withInput($request->only('email'));
        }

        $credentials = $validator->validated();
        $credentials['email'] = mb_strtolower(trim($credentials['email']));
        $credentials['is_active'] = true;

        $guard = Auth::guard('node_operator');
        if (! $guard->attempt($credentials)) {
            return redirect()->to(route('node.operator.login', absolute: false))->withErrors([
                'email' => 'These credentials do not match our records.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();
        $operator = $guard->user();
        $audit->recordLoginSucceeded((int) $operator->getAuthIdentifier());

        return redirect()->to(route('node.operator.dashboard', absolute: false));
    }

    public function dashboard(): View
    {
        return view('node-operator.dashboard', [
            'operator' => Auth::guard('node_operator')->user(),
        ]);
    }

    public function logout(Request $request, NodeOperatorAuditRecorder $audit): RedirectResponse
    {
        $operatorId = Auth::guard('node_operator')->id();
        $audit->recordLogoutSucceeded((int) $operatorId);

        Auth::guard('node_operator')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(route('node.operator.login', absolute: false));
    }
}

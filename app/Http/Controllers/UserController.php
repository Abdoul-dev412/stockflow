<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Exceptions\UserManagementException;
use App\Http\Requests\UserPasswordRequest;
use App\Http\Requests\UserRequest;
use App\Models\User;
use App\Services\UserManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private UserManager $users)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? null;

        $users = User::query()
            ->when($search !== '', fn ($q) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
            ))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('users.index', ['users' => $users, 'roles' => Role::cases()]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('users.create', ['roles' => Role::cases()]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->users->create(
                $request->user(), $data['name'], $data['email'], Role::from($data['role']), $data['password'],
            );
        } catch (UserManagementException $e) {
            return back()->withInput()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('users.index')->with(
            'status',
            'Compte créé. Communique-lui le mot de passe temporaire : il devra le changer à sa première connexion.'
        );
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);

        return view('users.edit', ['user' => $user, 'roles' => Role::cases()]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->users->update(
                $request->user(), $user, $data['name'], $data['email'], Role::from($data['role']),
            );
        } catch (UserManagementException $e) {
            return back()->withInput()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('users.index')->with('status', 'Compte modifié.');
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        try {
            $updated = $this->users->toggle($request->user(), $user);
        } catch (UserManagementException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return back()->with('status', $updated->is_active ? 'Compte réactivé.' : 'Compte désactivé.');
    }

    public function editPassword(User $user): View
    {
        Gate::authorize('update', $user);

        return view('users.password', ['user' => $user]);
    }

    public function updatePassword(UserPasswordRequest $request, User $user): RedirectResponse
    {
        try {
            $this->users->resetPassword($request->user(), $user, $request->validated('password'));
        } catch (UserManagementException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('users.index')->with(
            'status',
            'Mot de passe réinitialisé. Communique-le au titulaire : il devra le changer à sa prochaine connexion.'
        );
    }
}
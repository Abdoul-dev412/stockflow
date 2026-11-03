<?php

namespace App\Http\Controllers;

use App\Enums\AnomalyRule;
use App\Enums\AnomalyStatus;
use App\Enums\AuditAction;
use App\Models\Anomaly;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AnomalyController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Anomaly::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(AnomalyStatus::class)],
            'rule' => ['nullable', Rule::enum(AnomalyRule::class)],
        ]);

        // Sans paramètre : les anomalies à examiner. Un statut vide (« Tous ») montre tout.
        $status = $request->has('status') ? ($filters['status'] ?? null) : AnomalyStatus::New->value;

        $anomalies = Anomaly::query()
            ->with(['product', 'user:id,name', 'reviewer:id,name'])
            ->when($status, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['rule'] ?? null, fn ($q, $rule) => $q->where('rule_code', $rule))
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('anomalies.index', [
            'anomalies' => $anomalies,
            'status' => $status,
            'rules' => AnomalyRule::cases(),
            'statuses' => AnomalyStatus::cases(),
            'newCount' => Anomaly::where('status', AnomalyStatus::New->value)->count(),
        ]);
    }

    public function update(Request $request, Anomaly $anomaly, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('update', $anomaly);

        $data = $request->validate([
            'status' => ['required', Rule::in([AnomalyStatus::Reviewed->value, AnomalyStatus::Ignored->value])],
            'comment' => [
                Rule::requiredIf($request->input('status') === AnomalyStatus::Ignored->value),
                'nullable', 'string', 'max:500',
            ],
        ], [
            'status.required' => 'Choisis une action.',
            'status.in' => "Cette action n'est pas valide.",
            'comment.required' => 'Un commentaire est obligatoire pour ignorer une anomalie.',
            'comment.max' => 'Le commentaire ne doit pas dépasser 500 caractères.',
        ]);

        $newStatus = AnomalyStatus::from($data['status']);
        $comment = filled($data['comment'] ?? null) ? trim($data['comment']) : null;

        $handled = DB::transaction(function () use ($anomaly, $newStatus, $comment, $request, $audit) {
            // Relecture sous verrou : deux clics simultanés ne traitent pas l'anomalie deux fois.
            $locked = Anomaly::query()->lockForUpdate()->findOrFail($anomaly->id);

            if ($locked->status !== AnomalyStatus::New) {
                return false;
            }

            $locked->forceFill([
                'status' => $newStatus,
                'comment' => $comment,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            $audit->record(
                $request->user(),
                $newStatus === AnomalyStatus::Ignored ? AuditAction::AnomalyIgnored : AuditAction::AnomalyReviewed,
                $locked,
                array_filter(['rule' => $locked->rule_code->value, 'comment' => $comment]),
            );

            return true;
        });

        if (! $handled) {
            return back()->withErrors(['form' => 'Cette anomalie a déjà été traitée.']);
        }

        return back()->with('status', $newStatus === AnomalyStatus::Ignored ? 'Anomalie ignorée.' : 'Anomalie marquée comme examinée.');
    }
}
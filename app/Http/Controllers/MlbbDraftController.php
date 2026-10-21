<?php

namespace App\Http\Controllers;

use App\Services\MlbbDraft\DraftData;
use App\Services\MlbbDraft\DraftTemplates;
use App\Services\MlbbDraft\Ranks;
use App\Services\MlbbDraft\TemplateForbidden;
use App\Services\MlbbDraft\TemplateNotFound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class MlbbDraftController extends Controller
{
    public function index(DraftTemplates $templates)
    {
        return view('mlbb-draft.index', [
            'config' => [
                'ranks' => Ranks::all(),
                'windows' => Ranks::WINDOWS,
                'defaultWindow' => Ranks::DEFAULT_WINDOW,
                'templates' => $templates->all(),
            ],
        ]);
    }

    // ------------------------------------------------------------ templates

    public function templates(DraftTemplates $templates): JsonResponse
    {
        return response()->json(['templates' => $templates->all()]);
    }

    /** Anyone can share a template; the returned key is the only way to edit or delete it. */
    public function storeTemplate(Request $request, DraftTemplates $templates): JsonResponse
    {
        [$template, $key] = $templates->create($request->all());

        return response()->json(['template' => $template, 'key' => $key], 201);
    }

    public function updateTemplate(Request $request, DraftTemplates $templates, string $id): JsonResponse
    {
        return $this->guarded(fn () => response()->json([
            'template' => $templates->update($id, $request->all(), $request->header('X-Template-Key')),
        ]));
    }

    public function destroyTemplate(Request $request, DraftTemplates $templates, string $id): JsonResponse
    {
        return $this->guarded(function () use ($templates, $id, $request) {
            $templates->delete($id, $request->header('X-Template-Key'));

            return response()->json(['deleted' => $id]);
        });
    }

    private function guarded(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (TemplateForbidden $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (TemplateNotFound $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /** Heroes + win/pick/ban rates for one rank bucket. */
    public function data(Request $request, DraftData $data): JsonResponse
    {
        ['rank' => $rank, 'days' => $days] = $this->filters($request);

        try {
            $heroes = $data->heroes();
            $stats = $data->stats($rank, $days);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Hero data is unavailable right now. Please try again in a minute.'], 503);
        }

        return response()->json([
            'rank' => $rank,
            'days' => $days,
            'updatedAt' => $data->statsUpdatedAt($rank, $days),
            'heroes' => $heroes,
            'stats' => (object) $stats,
        ]);
    }

    /** Counter + teammate data for the heroes currently in the draft. */
    public function matchups(Request $request, DraftData $data): JsonResponse
    {
        ['rank' => $rank, 'days' => $days] = $this->filters($request);

        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && $id < 10000)
            ->unique()
            ->take(20)
            ->values()
            ->all();

        return response()->json(['matchups' => (object) ($ids ? $data->matchups($ids, $rank, $days) : [])]);
    }

    private function filters(Request $request): array
    {
        $valid = $request->validate([
            'rank' => ['nullable', Rule::in(Ranks::API_RANKS)],
            'days' => ['nullable', Rule::in(array_map('strval', Ranks::WINDOWS))],
        ]);

        return ['rank' => $valid['rank'] ?? 'all', 'days' => (int) ($valid['days'] ?? Ranks::DEFAULT_WINDOW)];
    }
}

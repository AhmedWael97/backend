<?php

namespace App\Http\Controllers\Domain;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Pipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineManagementController extends Controller
{
    public function index(Request $request, Domain $domain): JsonResponse
    {
        $this->authorizeUser($request, $domain);

        return $this->success(Pipeline::where('domain_id', $domain->id)->with('steps')->get());
    }

    public function store(Request $request, Domain $domain): JsonResponse
    {
        $this->authorizeUser($request, $domain);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'steps' => ['array'],
            'steps.*.name' => ['required', 'string', 'max:120'],
            'steps.*.url_pattern' => ['required', 'string', 'max:500'],
            'steps.*.order' => ['required', 'integer', 'min:1'],
        ]);

        $pipeline = Pipeline::create([
            'domain_id' => $domain->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        foreach ($data['steps'] ?? [] as $step) {
            $pipeline->steps()->create($step);
        }

        return $this->success($pipeline->load('steps'), 201);
    }

    public function update(Request $request, Domain $domain, Pipeline $pipeline): JsonResponse
    {
        $this->authorizeUser($request, $domain);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
        ]);

        $pipeline->update($data);

        return $this->success($pipeline->fresh());
    }

    public function destroy(Request $request, Domain $domain, Pipeline $pipeline): JsonResponse
    {
        $this->authorizeUser($request, $domain);
        $pipeline->delete();

        return $this->success(['message' => 'Pipeline deleted.']);
    }

    /**
     * POST /api/domains/{domain}/pipelines/{pipeline}/steps
     */
    public function addStep(Request $request, Domain $domain, Pipeline $pipeline): JsonResponse
    {
        $this->authorizeUser($request, $domain);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url_pattern' => ['required', 'string', 'max:500'],
            'match_type' => ['sometimes', 'string', 'in:contains,equals,starts_with,regex'],
        ]);

        $position = $pipeline->steps()->count();
        $step = $pipeline->steps()->create(array_merge($data, ['order' => $position + 1]));

        return $this->success($step, 201);
    }

    /**
     * DELETE /api/domains/{domain}/pipelines/{pipeline}/steps/{step}
     */
    public function removeStep(Request $request, Domain $domain, Pipeline $pipeline, \App\Models\PipelineStep $step): JsonResponse
    {
        $this->authorizeUser($request, $domain);

        if ((int) $step->pipeline_id !== $pipeline->id) {
            abort(404);
        }

        $step->delete();

        return $this->success(['message' => 'Step removed.']);
    }

    /**
     * POST /api/domains/{domain}/pipelines/{pipeline}/reorder
     */
    public function reorderSteps(Request $request, Domain $domain, Pipeline $pipeline): JsonResponse
    {
        $this->authorizeUser($request, $domain);

        $steps = $request->validate([
            'steps' => ['required', 'array'],
            'steps.*.id' => ['required', 'integer'],
            'steps.*.position' => ['required', 'integer', 'min:0'],
        ])['steps'];

        foreach ($steps as $item) {
            $pipeline->steps()->where('id', $item['id'])->update(['order' => $item['position']]);
        }

        return $this->success($pipeline->load('steps'));
    }

    private function authorizeUser(Request $request, Domain $domain): void
    {
        if ((int) $domain->user_id !== $request->user()->id) {
            abort(403);
        }
    }
}
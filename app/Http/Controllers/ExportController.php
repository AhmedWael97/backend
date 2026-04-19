<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessExportJob;
use App\Models\Domain;
use App\Models\ExportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain_id' => ['required', 'integer'],
            'type' => ['required', 'in:visitors,events,funnel,ai'],
            'format' => ['required', 'in:csv,excel'],
            'filters' => ['nullable', 'array'],
        ]);

        Domain::where('id', $data['domain_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $export = ExportJob::create([
            'user_id' => $request->user()->id,
            'domain_id' => $data['domain_id'],
            'type' => $data['type'],
            'format' => $data['format'],
            'filters' => $data['filters'] ?? [],
            'status' => 'pending',
        ]);

        ProcessExportJob::dispatch($export->id)->onQueue('exports');

        return $this->success($export, 202);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $export = ExportJob::where('user_id', $request->user()->id)
            ->findOrFail($id);

        return $this->success($export);
    }

    public function download(Request $request, int $id)
    {
        $export = ExportJob::where('user_id', $request->user()->id)
            ->where('status', 'done')
            ->findOrFail($id);

        if (!$export->file_path || !Storage::exists($export->file_path)) {
            abort(404, 'Export file not found.');
        }

        return Storage::download(
            $export->file_path,
            basename($export->file_path)
        );
    }
}

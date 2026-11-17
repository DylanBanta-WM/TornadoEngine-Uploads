<?php

namespace TornadoEngine\Uploads\Http;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;
use TornadoEngine\Uploads\Models\Upload;
use TornadoEngine\Uploads\Uploads;

class UploadsController extends Controller
{
    public function store(Request $request, Uploads $uploads): JsonResponse|RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:20'],
            'files.*' => ['file', 'max:'.(Uploads::maxMb() * 1024)],
            'share' => ['nullable', 'in:'.implode(',', Uploads::SHARES)],
        ], ['files.*.max' => __('Files can be up to :size MB.', ['size' => Uploads::maxMb()]), 'files.*.uploaded' => __('Files can be up to :size MB.', ['size' => Uploads::maxMb()])]);

        $stored = [];
        try {
            foreach ($request->file('files') as $file) {
                $stored[] = $uploads->store($file, $request->user(), (string) $request->input('share', 'text'));
            }
        } catch (RuntimeException $e) {
            return $request->expectsJson() ? response()->json(['message' => $e->getMessage()], 422) : back()->withErrors(['files' => $e->getMessage()]);
        }

        return $request->expectsJson()
            ? response()->json(['uploads' => array_map(fn ($upload) => $this->json($upload), $stored)])
            : back()->with('uploads_status', trans_choice('Uploaded :count file.|Uploaded :count files.', count($stored), ['count' => count($stored)]));
    }

    public function share(Request $request, Upload $upload, Uploads $uploads): JsonResponse|RedirectResponse
    {
        $this->own($request, $upload);
        $data = $request->validate(['share' => ['required', 'in:'.implode(',', Uploads::SHARES)]]);
        $uploads->share($upload, $data['share'], $request->boolean('confirm_faces'));

        return $request->expectsJson() ? response()->json($this->json($upload->fresh())) : back();
    }

    public function destroy(Request $request, Upload $upload, Uploads $uploads): JsonResponse|RedirectResponse
    {
        $this->own($request, $upload);
        $uploads->delete($upload);

        return $request->expectsJson() ? response()->json(['deleted' => true]) : back()->with('uploads_status', __('Deleted.'));
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate(['max_mb' => ['required', 'integer', 'min:1', 'max:'.Uploads::LIMIT_MB]]);
        Setting::put('uploads.max_mb', (string) $data['max_mb']);

        return back()->with('uploads_status', __('Saved.'));
    }

    private function own(Request $request, Upload $upload): void
    {
        abort_unless($upload->user_id === $request->user()->id, 404);
    }

    /** For Chat's message box. */
    private function json(Upload $upload): array
    {
        return [
            'id' => $upload->id,
            'name' => $upload->name,
            'share' => $upload->share,
            'image' => $upload->isImage(),
            'faces' => $upload->faces,
            'waiting' => $upload->waitingForFaces(),
            'error' => $upload->error,
            'url' => [
                'share' => route('uploads.share', $upload),
                'destroy' => route('uploads.destroy', $upload),
            ],
        ];
    }
}

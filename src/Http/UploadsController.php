<?php

namespace TornadoEngine\Uploads\Http;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use TornadoEngine\Uploads\Models\Upload;
use TornadoEngine\Uploads\Uploads;

class UploadsController extends Controller
{
    public function store(Request $request, Uploads $uploads): JsonResponse|RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:20'],
            'files.*' => ['file', 'max:'.(Uploads::maxMb() * 1024)],
            'student' => ['required', 'boolean'],
        ], ['files.*.max' => __('Files can be up to :size MB.', ['size' => Uploads::maxMb()]), 'files.*.uploaded' => __('Files can be up to :size MB.', ['size' => Uploads::maxMb()])]);

        $stored = [];
        try {
            foreach ($request->file('files') as $file) {
                $stored[] = $uploads->store($file, $request->user(), $request->boolean('student'));
            }
        } catch (RuntimeException $e) {
            return $request->expectsJson() ? response()->json(['message' => $e->getMessage()], 422) : back()->withErrors(['files' => $e->getMessage()]);
        }

        return $request->expectsJson()
            ? response()->json(['uploads' => array_map(fn ($upload) => $this->json($upload), $stored)])
            : back()->with('uploads_status', trans_choice('Uploaded :count file.|Uploaded :count files.', count($stored), ['count' => count($stored)]));
    }

    /** Faces and logos found in an image: cover them, or leave them. */
    public function review(Request $request, Upload $upload, Uploads $uploads): JsonResponse|RedirectResponse
    {
        $this->own($request, $upload);
        $request->validate(['boxes' => ['sometimes', 'array', 'max:200'], 'boxes.*' => ['array', 'size:4'], 'boxes.*.*' => ['integer', 'min:0']]);
        $boxes = $request->has('boxes') ? array_map(fn ($box) => array_map('intval', array_values($box)), $request->input('boxes', [])) : null;
        $uploads->review($upload, $request->boolean('cover'), $boxes);

        return $request->expectsJson() ? response()->json($this->json($upload->fresh())) : back();
    }

    /** For the uploader: the image with what's covered, before they decide, without boxes (the page draws them). */
    public function preview(Request $request, Upload $upload, Uploads $uploads): BinaryFileResponse
    {
        $this->own($request, $upload);
        abort_unless(is_file($path = $uploads->previewPath($upload)), 404);

        return response()->file($path, ['Cache-Control' => 'private, no-store']);
    }

    /** For the uploader: the image they uploaded, as it is. */
    public function image(Request $request, Upload $upload): BinaryFileResponse
    {
        $this->own($request, $upload);
        abort_unless($upload->isImage() && Storage::disk('uploads')->exists($upload->path), 404);

        // Only real raster images are shown as images (never SVG or anything that could run in the page).
        $type = preg_match('#^image/(png|jpeg|gif|bmp|webp|tiff)$#', (string) $upload->mime) ? $upload->mime : 'application/octet-stream';

        return response()->file(Storage::disk('uploads')->path($upload->path), ['Content-Type' => $type, 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
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

    public function addLogo(Request $request, Uploads $uploads): RedirectResponse
    {
        $request->validate(['logo' => ['required', 'image', 'max:10240']]);
        $uploads->addLogo($request->file('logo'));

        return back()->with('uploads_status', __('Logo added.'));
    }

    public function logo(string $logo): BinaryFileResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9]+\.[a-z]+$/', $logo) && Storage::disk('uploads')->exists('logos/'.$logo), 404);

        return response()->file(Storage::disk('uploads')->path('logos/'.$logo));
    }

    public function removeLogo(string $logo): RedirectResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9]+\.[a-z]+$/', $logo), 404);
        Storage::disk('uploads')->delete('logos/'.$logo);

        return back()->with('uploads_status', __('Logo removed.'));
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
            'student' => $upload->student,
            'image' => $upload->isImage(),
            'text' => $upload->has_text,
            'waiting' => $upload->waiting(),
            'faces' => count($upload->findings['faces'] ?? []),
            'logos' => count($upload->findings['logos'] ?? []),
            'names' => count($upload->findings['names'] ?? []),
            'covered' => $upload->covered,
            'boxes' => $upload->waiting() ? app(Uploads::class)->boxes($upload) : [],
            // face, logo or name for each box (drawn red, blue, green)
            'kinds' => $upload->waiting() ? array_column(app(Uploads::class)->found($upload), 'kind') : [],
            'error' => $upload->error,
            'preview' => $upload->isImage() && is_file(app(Uploads::class)->previewPath($upload)) ? route('uploads.preview', $upload).'?v='.$upload->updated_at?->timestamp : null,
            'url' => [
                'review' => route('uploads.review', $upload),
                'destroy' => route('uploads.destroy', $upload),
            ],
        ];
    }
}

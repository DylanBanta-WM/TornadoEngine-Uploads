<?php

namespace TornadoEngine\Uploads;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use TornadoEngine\Broker\Broker;
use TornadoEngine\Uploads\Models\Upload;

/**
 * Uploaded files. Originals stay in the website's uploads volume (the harness can't see it);
 * Claude gets copies in storage/app/claude/uploads/<id>: the file's text run through the broker,
 * and for images a copy with the text the broker would replace covered in black, plus faces and
 * district logos covered if the uploader asks.
 */
class Uploads
{
    /** The most the server accepts (PHP and nginx are set to it). */
    public const LIMIT_MB = 1024;

    public const DEFAULT_MB = 100;

    public const IMAGES = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'tif', 'tiff', 'webp'];

    public function __construct(private Broker $broker, private Extractor $extractor) {}

    public static function maxMb(): int
    {
        return max(1, min(self::LIMIT_MB, (int) (Setting::get('uploads.max_mb') ?? self::DEFAULT_MB)));
    }

    /**
     * Whether originals are kept where the harness can't read them: the uploads folder is its own
     * volume. (Off in tests, which have no volume.)
     */
    public static function protectedStorage(): bool
    {
        if (! config('uploads.require_volume', true)) {
            return true;
        }
        $dir = storage_path('app/uploads');
        $folder = @stat($dir);
        $parent = @stat(dirname($dir));

        return $folder && $parent && $folder['dev'] !== $parent['dev'];
    }

    /** @param  bool  $student  the uploader says it contains student information */
    public function store(UploadedFile $file, User $user, bool $student): Upload
    {
        if (! self::protectedStorage()) {
            throw new RuntimeException(__('Uploads aren\'t ready on this server: the uploads volume isn\'t mounted. An admin runs Rebuild in Administration → Updates.'));
        }
        if ($file->getSize() > self::maxMb() * 1024 * 1024) {
            throw new RuntimeException(__(':name is larger than :size MB.', ['name' => $file->getClientOriginalName(), 'size' => self::maxMb()]));
        }

        $upload = Upload::create([
            'user_id' => $user->id,
            'name' => $this->cleanName($file->getClientOriginalName()),
            'mime' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'path' => $file->store((string) $user->id, 'uploads'),
            'share' => 'text',
            'student' => $student,
        ]);
        $this->prepare($upload);

        return $upload->fresh();
    }

    /**
     * The uploader looked at the faces and logos found in an image: cover them, or leave them.
     * $boxes, when given, are the boxes they settled on (found ones removed, their own added).
     *
     * @param  list<array{int, int, int, int}>|null  $boxes
     */
    public function review(Upload $upload, bool $cover, ?array $boxes = null): void
    {
        if ($boxes !== null) {
            $upload->findings = [...($upload->findings ?? []), 'chosen' => array_values($boxes)];
        }
        $upload->fill(['answered' => true, 'cover' => $cover]);
        $this->prepare($upload);
    }

    /** @return list<array{int, int, int, int}> faces and logos found, or the boxes the uploader chose */
    public function boxes(Upload $upload): array
    {
        return $upload->findings['chosen'] ?? array_merge($upload->findings['faces'] ?? [], array_map(fn ($logo) => array_slice($logo, 0, 4), $upload->findings['logos'] ?? []));
    }

    /** Makes Claude's copies. */
    public function prepare(Upload $upload): void
    {
        File::deleteDirectory($this->dir($upload));
        $upload->fill(['error' => null, 'covered' => 0, 'has_text' => false]);
        $original = Storage::disk('uploads')->path($upload->path);

        try {
            if ($this->broker->notReady() !== null) {
                throw new RuntimeException((string) $this->broker->notReady());
            }
            $upload->isImage() ? $this->prepareImage($upload, $original) : $this->writeText($upload, $this->extractor->text($original, $upload->name));
        } catch (RuntimeException $e) {
            File::deleteDirectory($this->dir($upload));
            $upload->error = mb_substr($e->getMessage(), 0, 250);
        }
        $upload->save();
    }

    private function prepareImage(Upload $upload, string $original): void
    {
        // Each word and where it is; words (or lines) the broker would change get a black bar.
        try {
            $lines = $this->words($original);
        } catch (RuntimeException $e) {
            if ($upload->student) {
                throw new RuntimeException(__('Couldn\'t read the text in this image, so it isn\'t given to Claude.'));
            }
            $lines = [];
        }
        $black = [];
        $words = array_merge(...array_values($lines ?: [[]]));
        $filteredWords = $this->broker->filterEach(array_column($words, 'text'));
        $lineTexts = array_map(fn ($line) => implode(' ', array_column($line, 'text')), $lines);
        $filteredLines = $this->broker->filterEach($lineTexts);
        $i = 0;
        foreach ($lines as $key => $line) {
            $hit = false;
            foreach ($line as $word) {
                if ($filteredWords[$i++] !== $word['text']) {
                    $black[] = $word['box'];
                    $hit = true;
                }
            }
            if (! $hit && $filteredLines[$key] !== $lineTexts[$key]) {
                array_push($black, ...array_column($line, 'box'));
            }
        }
        $this->writeText($upload, implode("\n", $filteredLines), filtered: true);

        // Faces and district logos: shown to the uploader, who decides.
        $upload->findings ??= ['faces' => $this->faces($original), 'logos' => $this->logos($original)];

        if ($upload->waiting()) {
            // The red boxes are drawn on the page, where the uploader can change them.
            $this->render($original, $this->previewPath($upload), $black);

            return;
        }
        $cover = $upload->cover ? $this->boxes($upload) : [];
        $black = array_merge($black, $cover);
        File::ensureDirectoryExists($this->dir($upload));
        $this->render($original, $this->dir($upload).'/image.png', $black);
        File::ensureDirectoryExists(dirname($this->previewPath($upload)));
        copy($this->dir($upload).'/image.png', $this->previewPath($upload));
        $upload->covered = count($black);
    }

    private function writeText(Upload $upload, ?string $text, bool $filtered = false): void
    {
        if ($text === null || trim($text) === '') {
            return;
        }
        File::ensureDirectoryExists($this->dir($upload));
        file_put_contents($this->dir($upload).'/text.txt', $filtered ? $text : $this->broker->filter($text));
        $upload->has_text = true;
    }

    /**
     * The words in an image (tesseract), by line.
     *
     * @return array<string, list<array{text: string, box: array{int, int, int, int}}>>
     */
    public function words(string $path): array
    {
        $result = Process::timeout(180)->run(['tesseract', $path, 'stdout', 'tsv']);
        if (! $result->successful()) {
            throw new RuntimeException(__('Couldn\'t read the text in this image.'));
        }
        $lines = [];
        foreach (explode("\n", $result->output()) as $row) {
            $cells = explode("\t", rtrim($row, "\r"));
            if (count($cells) < 12 || $cells[0] !== '5' || trim($cells[11]) === '') {
                continue;
            }
            $lines["{$cells[2]}.{$cells[3]}.{$cells[4]}"][] = ['text' => trim($cells[11]), 'box' => [(int) $cells[6], (int) $cells[7], (int) $cells[8], (int) $cells[9]]];
        }

        return $lines;
    }

    /** @return list<array{int, int, int, int}> */
    public function faces(string $path): array
    {
        return $this->image(['faces', $path, config('uploads.haarcascades', '/usr/share/opencv4/haarcascades')]);
    }

    /** @return list<array{int, int, int, int, int}> boxes and which logo */
    public function logos(string $path): array
    {
        $logos = $this->logoFiles();

        return $logos ? $this->image(['logos', $path, ...array_map(fn ($logo) => Storage::disk('uploads')->path($logo), $logos)]) : [];
    }

    /** @return list<string> the district's logos (Administration → Uploads), paths on the uploads disk */
    public function logoFiles(): array
    {
        return Storage::disk('uploads')->files('logos');
    }

    public function addLogo(UploadedFile $file): void
    {
        $file->storeAs('logos', Str::random(12).'.'.strtolower($file->getClientOriginalExtension() ?: 'png'), 'uploads');
    }

    private function render(string $original, string $output, array $black, array $red = []): void
    {
        File::ensureDirectoryExists(dirname($output));
        $boxes = tempnam(sys_get_temp_dir(), 'boxes');
        file_put_contents($boxes, json_encode(['black' => array_values($black), 'red' => array_values($red)]));
        try {
            $this->image(['render', $original, $output, $boxes]);
        } finally {
            @unlink($boxes);
        }
        if (! is_file($output)) {
            throw new RuntimeException(__('Couldn\'t open this image.'));
        }
    }

    private function image(array $args): array
    {
        $result = Process::timeout(180)->run(['python3', __DIR__.'/../resources/bin/image.py', ...$args]);
        $data = json_decode(trim($result->output()), true);
        if (! $result->successful() || ! is_array($data) || isset($data['error'])) {
            throw new RuntimeException(__('Couldn\'t open this image.'));
        }

        return $data;
    }

    public function previewPath(Upload $upload): string
    {
        return Storage::disk('uploads')->path('previews/'.$upload->id.'.png');
    }

    /** @return array<string, string> name => path in the harness */
    public function forClaude(Upload $upload): array
    {
        $files = [];
        foreach (is_dir($this->dir($upload)) ? File::files($this->dir($upload)) : [] as $file) {
            $files[$file->getFilename()] = '/workspace/web/storage/app/claude/uploads/'.$upload->id.'/'.$file->getFilename();
        }
        ksort($files);

        return $files;
    }

    public function delete(Upload $upload): void
    {
        Storage::disk('uploads')->delete([$upload->path, 'previews/'.$upload->id.'.png']);
        File::deleteDirectory($this->dir($upload));
        $upload->delete();
    }

    /** Deletes what's attached to these, for example a deleted chat's messages. */
    public function forget(string $source, array $references): void
    {
        Upload::where('source', $source)->whereIn('reference', array_map('strval', $references))->get()->each(fn ($upload) => $this->delete($upload));
    }

    public function dir(Upload $upload): string
    {
        return storage_path('app/claude/uploads/'.$upload->id);
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/[^\pL\pN ._()+-]+/u', '_', basename($name)), ' .');

        return mb_substr($name !== '' ? $name : 'file', -150);
    }
}

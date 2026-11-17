<?php

namespace TornadoEngine\Uploads;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use TornadoEngine\Broker\Broker;
use TornadoEngine\Uploads\Models\Upload;

/**
 * Uploaded files. Originals stay in the website's uploads volume (the harness can't see it);
 * Claude gets copies in storage/app/claude/uploads/<id>: the file's text run through the broker,
 * and an image itself only when it's marked as having no student information.
 */
class Uploads
{
    /** The most the server accepts (PHP and nginx are set to it). */
    public const LIMIT_MB = 1024;

    public const DEFAULT_MB = 100;

    public const SHARES = ['text', 'none', 'image'];

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

    public function store(UploadedFile $file, User $user, string $share = 'text'): Upload
    {
        if (! self::protectedStorage()) {
            throw new RuntimeException(__('Uploads aren\'t ready on this server: the uploads volume isn\'t mounted. An admin runs docker compose up -d (or Rebuild in Administration → Updates).'));
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
        ]);
        $this->share($upload, $share);

        return $upload->fresh();
    }

    /**
     * Sets what Claude gets, and makes its copies.
     */
    public function share(Upload $upload, string $share, bool $confirmFaces = false): void
    {
        if (! in_array($share, self::SHARES, true) || ($share === 'image' && ! $upload->isImage())) {
            $share = 'text';
        }
        $upload->fill(['share' => $share, 'faces_confirmed' => $confirmFaces || ($upload->faces_confirmed && $share === 'image'), 'error' => null]);

        File::deleteDirectory($this->dir($upload));
        if ($share === 'none') {
            $upload->fill(['has_text' => false])->save();

            return;
        }

        $original = Storage::disk('uploads')->path($upload->path);
        try {
            $text = $this->extractor->text($original, $upload->name);
            $upload->has_text = $text !== null && trim($text) !== '';
            if ($upload->has_text) {
                if ($reason = $this->broker->notReady()) {
                    throw new RuntimeException($reason);
                }
                File::ensureDirectoryExists($this->dir($upload));
                file_put_contents($this->dir($upload).'/text.txt', $this->broker->filter($text));
            }

            if ($share === 'image') {
                $upload->faces ??= $this->faces($original);
                if (! $upload->waitingForFaces()) {
                    File::ensureDirectoryExists($this->dir($upload));
                    // Neutral names: file names can have someone's name in them.
                    copy($original, $this->dir($upload).'/image.'.strtolower(pathinfo($upload->name, PATHINFO_EXTENSION)));
                }
            }
        } catch (RuntimeException $e) {
            File::deleteDirectory($this->dir($upload));
            $upload->error = mb_substr($e->getMessage(), 0, 250);
        }
        $upload->save();
    }

    /** @return array<string, string> name => path in the harness */
    public function forClaude(Upload $upload): array
    {
        $files = [];
        foreach (is_dir($this->dir($upload)) ? File::files($this->dir($upload)) : [] as $file) {
            $files[$file->getFilename()] = '/workspace/web/storage/app/claude/uploads/'.$upload->id.'/'.$file->getFilename();
        }

        return $files;
    }

    public function delete(Upload $upload): void
    {
        Storage::disk('uploads')->delete($upload->path);
        File::deleteDirectory($this->dir($upload));
        $upload->delete();
    }

    /** Deletes what's attached to these, for example a deleted chat's messages. */
    public function forget(string $source, array $references): void
    {
        Upload::where('source', $source)->whereIn('reference', array_map('strval', $references))->get()->each(fn ($upload) => $this->delete($upload));
    }

    /** Faces found in an image, or null if it couldn't be checked. */
    public function faces(string $path): ?int
    {
        $result = Process::timeout(120)->run(['python3', __DIR__.'/../resources/bin/faces.py', $path, config('uploads.haarcascades', '/usr/share/opencv4/haarcascades')]);
        $count = trim($result->output());

        return $result->successful() && is_numeric($count) && (int) $count >= 0 ? (int) $count : null;
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

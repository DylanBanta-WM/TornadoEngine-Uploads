<?php

namespace TornadoEngine\Uploads\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use TornadoEngine\Broker\Broker;
use TornadoEngine\Uploads\Uploads;

class Upload extends Model
{
    protected $fillable = ['user_id', 'name', 'mime', 'size', 'path', 'share', 'faces', 'faces_confirmed', 'has_text', 'error', 'source', 'reference'];

    protected function casts(): array
    {
        return ['faces_confirmed' => 'boolean', 'has_text' => 'boolean', 'size' => 'integer', 'faces' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isImage(): bool
    {
        return in_array(strtolower(pathinfo($this->name, PATHINFO_EXTENSION)), Uploads::IMAGES, true);
    }

    /** The image is held back: a face was found (or it couldn't be checked) and that hasn't been confirmed. */
    public function waitingForFaces(): bool
    {
        return $this->share === 'image' && $this->faces !== 0 && ! $this->faces_confirmed;
    }

    /** @return array<string, string> what Claude can read, name => path in the harness */
    public function forClaude(): array
    {
        return app(Uploads::class)->forClaude($this);
    }

    /** One line for Claude about this file (its name through the broker too). */
    public function describeForClaude(): string
    {
        $name = app(Broker::class)->filter($this->name);
        $files = $this->forClaude();

        return '- '.$name.': '.match (true) {
            $this->share === 'none' => 'not given to you (it has student information)',
            $this->waitingForFaces() && ! $files => 'not given to you yet (waiting for them to confirm the image)',
            ! $files => 'no text could be read from it',
            default => implode(', ', $files).($this->waitingForFaces() ? ' (the image itself is waiting for them to confirm it)' : ''),
        };
    }
}

<?php

namespace TornadoEngine\Uploads\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use TornadoEngine\Broker\Broker;
use TornadoEngine\Uploads\Uploads;

class Upload extends Model
{
    protected $fillable = ['user_id', 'name', 'mime', 'size', 'path', 'share', 'student', 'findings', 'answered', 'cover', 'covered', 'has_text', 'error', 'source', 'reference'];

    protected function casts(): array
    {
        return ['student' => 'boolean', 'findings' => 'array', 'answered' => 'boolean', 'cover' => 'boolean', 'covered' => 'integer', 'has_text' => 'boolean', 'size' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isImage(): bool
    {
        return in_array(strtolower(pathinfo($this->name, PATHINFO_EXTENSION)), Uploads::IMAGES, true);
    }

    /** Faces, district logos or names were found in the image, and the uploader hasn't said what to cover. */
    public function waiting(): bool
    {
        return $this->isImage() && ! $this->answered && (($this->findings['faces'] ?? []) !== [] || ($this->findings['logos'] ?? []) !== [] || ($this->findings['names'] ?? []) !== []);
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
        $note = match (true) {
            $this->waiting() => ' (the image itself is waiting for them to check it)',
            $this->covered > 0 => ' (parts of the image are covered in black)',
            default => '',
        };

        return '- '.$name.': '.($files ? implode(', ', $files).$note : 'no text could be read from it'.$note);
    }
}

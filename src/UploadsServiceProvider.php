<?php

namespace TornadoEngine\Uploads;

use App\Administration\Administration;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use TornadoEngine\Access\Access;
use TornadoEngine\Nav\Nav;

class UploadsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Uploads::class);
        // Originals: in the uploads volume, which the harness doesn't have.
        config(['filesystems.disks.uploads' => ['driver' => 'local', 'root' => storage_path('app/uploads'), 'throw' => false]]);
    }

    public function boot(Administration $administration): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'uploads');
        $this->loadRoutesFrom(__DIR__.'/../routes/uploads.php');

        $administration->tab('uploads', __('Uploads'), order: 57);
        $administration->card('uploads::administration-card', tab: 'uploads');

        // The setup admin; with the Access module, roles can be given it.
        Gate::define('uploads.use', fn (User $user) => (bool) $user->is_admin);
        $this->callAfterResolving(Access::class, function (Access $access) {
            $access->permission('uploads.use', __('Upload files'), 'Uploads');
        });

        $this->callAfterResolving(Nav::class, function (Nav $nav) {
            $nav->add(__('Uploads'), 'uploads.index', active: 'uploads.*', order: 410, can: 'uploads.use');
        });
    }
}

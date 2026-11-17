<x-settings-card :title="__('Uploads')">
    @if (session('uploads_status'))
        <p class="text-green-700 dark:text-green-400">{{ session('uploads_status') }}</p>
    @endif
    @unless (\TornadoEngine\Uploads\Uploads::protectedStorage())
        <p class="rounded-md border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 px-4 py-3 text-amber-900 dark:text-amber-200">{{ __('Uploads are off until the uploads volume is mounted: run Rebuild in Administration → Updates.') }}</p>
    @endunless
    <form method="POST" action="{{ route('uploads.settings') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
        @csrf
        <div class="flex-1">
            <x-input-label for="max_mb" :value="__('Largest file (MB, up to :limit)', ['limit' => \TornadoEngine\Uploads\Uploads::LIMIT_MB])" />
            <x-text-input id="max_mb" name="max_mb" type="number" min="1" :max="\TornadoEngine\Uploads\Uploads::LIMIT_MB" class="mt-1 block w-full" :value="old('max_mb', \TornadoEngine\Uploads\Uploads::maxMb())" required />
            <x-input-error :messages="$errors->get('max_mb')" class="mt-2" />
        </div>
        <x-primary-button class="justify-center">{{ __('Save') }}</x-primary-button>
    </form>
</x-settings-card>

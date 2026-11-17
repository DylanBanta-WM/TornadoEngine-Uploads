@php($logos = app(\TornadoEngine\Uploads\Uploads::class)->logoFiles())

<div class="grid grid-cols-1 items-start gap-6 lg:col-span-2 lg:grid-cols-2">
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
            <x-input-label for="max_mb" :value="__('Max file size (MB, up to :limit)', ['limit' => \TornadoEngine\Uploads\Uploads::LIMIT_MB])" />
            <x-text-input id="max_mb" name="max_mb" type="number" min="1" :max="\TornadoEngine\Uploads\Uploads::LIMIT_MB" class="mt-1 block w-full" :value="old('max_mb', \TornadoEngine\Uploads\Uploads::maxMb())" required />
            <x-input-error :messages="$errors->get('max_mb')" class="mt-2" />
        </div>
        <x-primary-button class="justify-center">{{ __('Save') }}</x-primary-button>
    </form>
</x-settings-card>

<x-settings-card :title="__('Logos to look for in images')">
    @if ($logos)
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($logos as $logo)
                <li class="space-y-2 rounded-md border border-gray-200 dark:border-gray-700 p-2">
                    <img src="{{ route('uploads.logos.show', basename($logo)) }}" alt="" class="mx-auto h-20 object-contain">
                    <x-confirm :action="route('uploads.logos.remove', basename($logo))" method="DELETE" :message="__('Remove this logo?')" :confirm="__('Remove')" danger>
                        <x-slot name="trigger"><button type="button" class="min-h-11 w-full text-sm font-medium text-red-700 dark:text-red-400">{{ __('Remove') }}</button></x-slot>
                    </x-confirm>
                </li>
            @endforeach
        </ul>
    @endif
    <form method="POST" action="{{ route('uploads.logos.add') }}" enctype="multipart/form-data" class="flex flex-col gap-3 sm:flex-row sm:items-end">
        @csrf
        <div class="flex-1">
            <x-input-label for="logo" :value="__('Add a logo')" />
            <x-file-input id="logo" name="logo" accept="image/*" required class="mt-1" />
            <x-input-error :messages="$errors->get('logo')" class="mt-2" />
        </div>
        <x-primary-button class="justify-center">{{ __('Add') }}</x-primary-button>
    </form>
</x-settings-card>
</div>

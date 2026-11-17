@php
    $field = 'block w-full min-h-11 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 text-base focus:border-indigo-500 focus:ring-indigo-500';
    $labels = ['text' => __('Filtered text'), 'none' => __('Nothing (student information)'), 'image' => __('Image too (no student information)')];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Uploads') }}</h2>
    </x-slot>

    <div class="py-6 sm:py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <form method="POST" action="{{ route('uploads.store') }}" enctype="multipart/form-data" class="space-y-4 bg-white dark:bg-gray-800 p-4 sm:p-6 shadow-sm sm:rounded-lg">
                @csrf
                @if (session('uploads_status'))
                    <p class="text-green-700 dark:text-green-400">{{ session('uploads_status') }}</p>
                @endif
                <div>
                    <x-input-label for="files" :value="__('Files (up to :size MB each)', ['size' => \TornadoEngine\Uploads\Uploads::maxMb()])" />
                    <input id="files" name="files[]" type="file" multiple required class="mt-1 block w-full text-base text-gray-700 dark:text-gray-300 file:me-3 file:min-h-11 file:rounded-md file:border-0 file:bg-gray-100 dark:file:bg-gray-700 file:px-4 file:font-medium file:text-gray-800 dark:file:text-gray-200">
                    <x-input-error :messages="array_merge($errors->get('files'), $errors->get('files.*') ? array_merge(...array_values($errors->get('files.*'))) : [])" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="share" :value="__('What Claude gets')" />
                    <select id="share" name="share" class="mt-1 {{ $field }}">
                        @include('uploads::share-options')
                    </select>
                </div>
                @include('uploads::warning')
                <div class="flex justify-end">
                    <x-primary-button>{{ __('Upload') }}</x-primary-button>
                </div>
            </form>

            @if ($uploads->isNotEmpty())
                <ul class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                    @foreach ($uploads as $upload)
                        <li class="space-y-3 p-4 sm:px-6">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                <p class="min-w-0 font-medium text-gray-900 dark:text-gray-100 [overflow-wrap:anywhere]">{{ $upload->name }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Number::fileSize($upload->size) }} · {{ $upload->created_at->diffForHumans() }}</p>
                            </div>
                            @if ($upload->error)
                                <p class="text-sm text-red-700 dark:text-red-400">{{ $upload->error }}</p>
                            @elseif ($upload->waitingForFaces())
                                <form method="POST" action="{{ route('uploads.share', $upload) }}" class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 px-3 py-2 text-sm text-amber-900 dark:text-amber-200">
                                    @csrf
                                    <input type="hidden" name="share" value="image">
                                    <input type="hidden" name="confirm_faces" value="1">
                                    <span>{{ $upload->faces === null ? __('Couldn\'t check this image for faces. Claude gets only its text until you confirm.') : trans_choice('A face was found. Claude gets only its text until you confirm.|:count faces were found. Claude gets only its text until you confirm.', $upload->faces, ['count' => $upload->faces]) }}</span>
                                    <x-secondary-button type="submit">{{ __('Give Claude the image') }}</x-secondary-button>
                                </form>
                            @elseif ($upload->share !== 'none' && ! $upload->forClaude())
                                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No text could be read from it.') }}</p>
                            @endif
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <form method="POST" action="{{ route('uploads.share', $upload) }}" class="flex min-w-0 flex-1 gap-2" x-data>
                                    @csrf
                                    <label for="share-{{ $upload->id }}" class="sr-only">{{ __('What Claude gets') }}</label>
                                    <select id="share-{{ $upload->id }}" name="share" class="{{ $field }} max-w-sm" x-on:change="$el.form.submit()">
                                        @foreach (['text', 'none', 'image'] as $value)
                                            @continue($value === 'image' && ! $upload->isImage())
                                            <option value="{{ $value }}" @selected($upload->share === $value)>{{ $labels[$value] }}</option>
                                        @endforeach
                                    </select>
                                </form>
                                <form method="POST" action="{{ route('uploads.destroy', $upload) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-danger-button>{{ __('Delete') }}</x-danger-button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-app-layout>

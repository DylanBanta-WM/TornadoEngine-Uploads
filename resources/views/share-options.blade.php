{{-- What Claude gets from a file. $image: whether the image itself can be offered. --}}
<option value="text">{{ __('Filtered text') }}</option>
<option value="none">{{ __('Nothing (student information)') }}</option>
@if ($image ?? true)
    <option value="image">{{ __('Image too (no student information)') }}</option>
@endif

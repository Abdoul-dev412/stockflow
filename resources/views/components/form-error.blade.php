@if ($errors->has('form'))
    <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">
        {{ $errors->first('form') }}
    </div>
@endif
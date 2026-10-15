@if (session('status'))
    <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">
        {{ session('status') }}
    </div>
@endif
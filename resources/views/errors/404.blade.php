@extends('layouts.main')

@section('title', 'Snipto - Page Not Found')

@section('content')
    <div class="flex flex-col items-center justify-center text-center space-y-8 max-w-2xl w-full">
        <!-- 404 Message -->
        <div class="space-y-4">
            <h1 class="text-6xl sm:text-7xl font-extrabold text-indigo-500">404</h1>
            <p class="text-lg sm:text-xl text-gray-600 dark:text-gray-300">
                {!! __('Oops! That’s not a valid snipto!') !!}
            </p>
        </div>

        <!-- Call to Action -->
        <div class="space-y-4">
            <p class="text-lg sm:text-xl text-gray-600 dark:text-gray-300">
                {!! __('Snipto URLs can only contain') !!} <span class="font-bold">{!! __('letters and numbers (A–Z, 0–9)') !!}</span>.
            </p>
            <div>
                <a href="/" class="inline-block bg-indigo-500 text-white px-6 py-3 rounded-lg hover:bg-indigo-600 transition font-bold text-lg">
                    {!! __('Back to Home') !!}
                </a>
            </div>
        </div>
    </div>
@endsection

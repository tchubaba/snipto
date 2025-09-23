{{-- resources/views/terms.blade.php --}}
@extends('layouts.main')

@section('content')
    <div class="max-w-3xl mx-auto py-12 px-6 text-gray-900 dark:text-gray-100">
        <h1 class="text-4xl font-bold mb-8 text-center text-indigo-300">Terms of Service</h1>

        <div class="space-y-8">

            <div>
                <p>
                    <strong>Welcome to Snipto!</strong>
                </p>
                <p>
                    Snipto is a free service designed to let you share text snippets securely. We use
                    <strong>end-to-end encryption</strong> to help keep your content private — your data
                    is encrypted in your browser and we never see the plain text.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-2 text-indigo-300">No guarantees</h2>
                <p>
                    While we do our best to keep things safe and private, Snipto is provided
                    <strong>as-is</strong>, for free, with <strong>no warranties</strong> of any kind.
                    You use it at your own risk.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-2 text-indigo-300">Your responsibility</h2>
                <p>
                    Sniptos are encrypted with a key that only you (or anyone you share the URL with) have.
                    If you lose the key, we cannot help recover your snipto.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-2 text-indigo-300">No liability</h2>
                <p>
                    By using Snipto, you agree that we <strong>cannot be held responsible</strong> for any
                    damages, losses, or problems that might result from using the service.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-2 text-indigo-300">Ephemeral nature</h2>
                <p>
                    Sniptos are <strong>ephemeral</strong> by default. They will automatically delete once
                    viewed, and unviewed sniptos are deleted after a set period (currently 7 days).
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-2 text-indigo-300">Content limitations</h2>
                <p>
                    Currently, Snipto only supports <strong>plain text snippets</strong>. Use common sense:
                    do not store illegal content or sensitive information that you wouldn’t want lost.
                </p>
            </div>

            <div>
                <p>
                    By using Snipto, you acknowledge and accept the terms as described here. If you have any questions
                    about how Snipto works, see our <a href="/faq" class="underline hover:text-indigo-500 dark:hover:text-indigo-400">FAQ</a>
                    or <a href="/contact" class="underline hover:text-indigo-500 dark:hover:text-indigo-400">Contact Us</a>.
                </p>
            </div>

        </div>
    </div>
@endsection

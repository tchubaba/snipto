{{-- resources/views/security.blade.php --}}
@extends('layouts.main')

@section('content')
    <div class="max-w-3xl mx-auto py-12 px-6 text-gray-900 dark:text-gray-100">
        <h1 class="text-4xl font-bold mb-8 text-center text-indigo-600 dark:text-indigo-400">{!! __('Data Safety Tips') !!}</h1>

        <div class="space-y-8">

            <div>
                <p>
                    {!! __('Using Snipto to share text snippets securely is convenient, but there are some simple steps you can follow to make it even safer. These security best practices help protect your snippets and reduce the risk of accidental exposure when creating, viewing, or sharing them. Following these tips ensures that both you and anyone you share with can enjoy a more secure experience.') !!}
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('Use Private Browsing') !!}</h2>
                <p>
                    {!! __('Open Snipto in a private or incognito window. This prevents browser extensions from accessing your snippet while you’re using the site.') !!}
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('Check Your Browser Extensions') !!}</h2>
                <p>
                    {!! __('Some extensions can read the data on websites you visit. Make sure you only have trusted extensions installed, and remove any you don’t recognize or need. If you share a Snipto with someone else, remind them to follow the same precautions on their end.') !!}
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('Copy Sniptos Right Away') !!}</h2>
                <p>
                    {!! __('Once you view a snippet and decide you want to keep it, copy it immediately. Sniptos are deleted from the server after viewing, so you won’t be able to see them again.') !!}
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('Share Sniptos Carefully') !!}</h2>
                <p class="mt-2">
                    {!! __('Always send the snippet URL (including the secret key, the #k= part) through secure channels like Signal or encrypted messaging.') !!}
                </p>
                <p class="mt-2">
                    {!! __('If you must share it via an unsecured channel, make sure the recipient opens and reads the Snipto right away. Since Sniptos are deleted once viewed, this limits exposure.') !!}
                </p>
                <p class="mt-2">
                    {!! __('Educate recipients to also follow the same safety practices, like using private browsing and trusted devices.') !!}
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('Be Extra Careful With Very Sensitive Info') !!}</h2>
                <p>
                    {!! __('If your snippet contains highly confidential information, consider using offline encrypted files instead of sharing through a website. Browsers can sometimes be exposed to extensions or other risks.') !!}
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('Keep Your Browser Up to Date') !!}</h2>
                <p>
                    {!! __('Using the latest version of your browser helps protect against security vulnerabilities.') !!}
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('Watch for Anything Unusual') !!}</h2>
                <p>
                    {!! __('If something seems off—like unexpected redirects or messages—close the page and report it. It could be a sign of an attempted attack.') !!}
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('Test in a Safe Environment') !!}</h2>
                <p>
                    {!! __('When possible, view your snippet on a separate device or a dedicated browser profile with no other tabs open. This reduces the risk of other sites accessing your snippet.') !!}
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('Report Security Concerns') !!}</h2>
                <p>
                    {!! __('If you notice a potential security issue or bug, contact the site administrators right away so it can be fixed.') !!}
                </p>
            </div>
        </div>
    </div>
@endsection

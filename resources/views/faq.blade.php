@extends('layouts.main')

@section('content')
    <div class="max-w-3xl mx-auto py-12 px-6 text-gray-900 dark:text-gray-100 leading-relaxed">

        <h1 class="text-4xl font-bold mb-10 text-center text-indigo-600 dark:text-indigo-400">{!! __('Frequently Asked Questions') !!}</h1>

        <!-- FAQ Index -->
        <div class="mb-12">
            <ul class="space-y-2 list-disc list-inside text-lg">
                <li><a href="#what-is-snipto" class="text-indigo-600 dark:text-indigo-400 hover:underline">{!! __('What is a Snipto?') !!}</a></li>
                <li><a href="#encryption-work" class="text-indigo-600 dark:text-indigo-400 hover:underline">{!! __('How does encryption work?') !!}</a></li>
                <li><a href="#stored-servers" class="text-indigo-600 dark:text-indigo-400 hover:underline">{!! __('What gets stored on your servers?') !!}</a></li>
                <li><a href="#encryption-key" class="text-indigo-600 dark:text-indigo-400 hover:underline">{!! __('Where is the encryption key?') !!}</a></li>
                <li><a href="#viewed" class="text-indigo-600 dark:text-indigo-400 hover:underline">{!! __('What happens to my Snipto after it’s viewed?') !!}</a></li>
                <li><a href="#never-viewed" class="text-indigo-600 dark:text-indigo-400 hover:underline">{!! __('What happens if my snipto is never viewed?') !!}</a></li>
                <li><a href="#lost-key" class="text-indigo-600 dark:text-indigo-400 hover:underline">{!! __('What if the decryption key is lost?') !!}</a></li>
                <li><a href="#content-types" class="text-indigo-600 dark:text-indigo-400 hover:underline">{!! __('What kinds of content can I store?') !!}</a></li>
                <li><a href="#personal-info" class="text-indigo-600 dark:text-indigo-400 hover:underline">{!! __('Is any personal information stored or collected with my Snipto?') !!}</a></li>
                <li><a href="#end-to-end" class="text-indigo-600 dark:text-indigo-400 hover:underline">{!! __('How can I be sure my Snipto is end-to-end encrypted?') !!}</a></li>
                <li><a href="#integrity" class="text-indigo-600 dark:text-indigo-400 hover:underline">{!! __('How do you ensure my data hasn’t been tampered with?') !!}</a></li>
            </ul>
        </div>

        <div class="space-y-10">
            <div id="what-is-snipto" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('What is a Snipto?') !!}</h2>
                <p>
                    {!! __('A Snipto is a small, private snippet of text that you can securely share with someone else. It’s like a tiny secret note that only the people with the full URL and encryption key can read. Once viewed, it vanishes automatically, so your message stays private and ephemeral.') !!}
                </p>
            </div>

            <div id="encryption-work" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-3 text-indigo-600 dark:text-indigo-400">{!! __('How does encryption work?') !!}</h2>
                <p>
                    {!! __('All encryption and decryption happens <strong>locally in your browser</strong>. When you create a Snipto, your device encrypts the content before it ever leaves your computer or phone.') !!}
                </p>
            </div>

            <div id="stored-servers" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-3 text-indigo-600 dark:text-indigo-400">{!! __('What gets stored on your servers?') !!}</h2>
                <p>
                    {!! __('We only store the <strong>encrypted form</strong> of your Snipto in our database. This means we never see the plain text. Without the encryption key, the stored data is meaningless to us.') !!}
                </p>
            </div>

            <div id="encryption-key" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('Where is the encryption key?') !!}</h2>
                <p class="mt-2">
                    {!! __('Depending on the protection mode you choose, the encryption key is handled differently:') !!}
                </p>
                <ul class="mt-2 space-y-2 list-disc list-inside ml-2">
                    <li>
                        <strong>{!! __('Random URL Secret Key:') !!}</strong> 
                        {!! __('The key is included in the URL after the <code>#</code> symbol. This part of the URL is <strong>never sent to our servers</strong>.') !!}
                    </li>
                    <li>
                        <strong>{!! __('Password Protected:') !!}</strong> 
                        {!! __('The key is the password you provide. We only store a hash of your password for verification — the actual password <strong>never leaves your browser</strong>.') !!}
                    </li>
                </ul>
                <p class="mt-4">
                    {!! __('In both cases, only someone with the correct key (the link fragment or the password) can decrypt the Snipto.') !!}
                </p>
            </div>

            <div id="viewed" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-3 text-indigo-600 dark:text-indigo-400">{!! __('What happens to my Snipto after it’s viewed?') !!}</h2>
                <p>
                    {!! __('Sniptos are <strong>ephemeral by default</strong>. Once decrypted and viewed, they are automatically deleted permanently and cannot be retrieved again.') !!}
                </p>
            </div>

            <div id="never-viewed" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-3 text-indigo-600 dark:text-indigo-400">{!! __('What happens if my snipto is never viewed?') !!}</h2>
                <p>
                    {!! __('If a snipto is never opened, it will be automatically deleted when it reaches its expiration time. By default, this happens one hour after creation. However, password-protected snippets can be configured to last for up to one week. Sniptos are meant to be temporary, and shorter time-to-live settings help keep your data more secure.') !!}
                </p>
            </div>

            <div id="lost-key" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-3 text-indigo-600 dark:text-indigo-400">{!! __('What if the decryption key is lost?') !!}</h2>
                <p>
                    {!! __('If you lose the key (the <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">#</code> part of the URL or the password you chose), the Snipto cannot be decrypted. Since we never store or know your keys or passwords, we cannot help you recover them. <strong>No key, no decryption.</strong>') !!}
                </p>
            </div>

            <div id="content-types" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-3 text-indigo-600 dark:text-indigo-400">{!! __('What kinds of content can I store?') !!}</h2>
                <p>
                    {!! __('Currently, Snipto only supports <strong>plain text snippets</strong>. In the future, we may expand support to other types of content while maintaining end-to-end encryption.') !!}
                </p>
            </div>

            <div id="personal-info" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('Is any personal information stored or collected with my Snipto?') !!}</h2>
                <p>
                    {!! __('No personal identifiable information is stored or collected with your Snipto. We only store the <strong>ciphertext</strong> (your Snipto in encrypted form), a unique random identifier used for decryption, and metadata about expiration and whether the Snipto has been viewed. Your IP address or any other identifying info is <strong>never stored</strong> with your Snipto.') !!}
                </p>
            </div>

            <div id="end-to-end" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('How can I be sure my Snipto is end-to-end encrypted?') !!}</h2>
                <p class="mt-2">
                    {!! __('All encryption and decryption happens <strong>directly in your browser</strong>. The JavaScript code that performs these operations (snipto.js) is fully readable for transparency.') !!}
                </p>
                <p class="mt-2">
                    {!! __('You can also inspect any network requests in your browser while creating or reading Sniptos. You will see that only scrambled, unreadable ciphertext is ever sent to our servers — the plain text of your Snipto and your encryption key are never transmitted or exposed.') !!}
                </p>
                <p class="mt-2">
                    {!! __('Snipto is an <strong>open-source project</strong>. All source code is available at') !!}
                    <a href="https://github.com/tchubaba/snipto" target="_blank" class="text-indigo-500 hover:underline">
                        https://github.com/tchubaba/snipto
                    </a>
                    {!! __('for anyone to inspect and verify. For those who want full control, you can also <strong>clone the repository and self-host</strong> your own instance of Snipto, ensuring your data never touches our servers.') !!}
                </p>
            </div>

            <div id="integrity" class="scroll-mt-12">
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('How do you ensure my data hasn’t been tampered with?') !!}</h2>
                <p class="mt-2">
                    {!! __('Every Snipto uses a "digital seal" (technically known as an HMAC). This acts like a tamper-evident sticker on a package. When your browser decrypts the Snipto, it automatically checks this seal. If even a single character of the encrypted data was changed while stored on our servers, the seal will be broken, and Snipto will refuse to display the content to protect you.') !!}
                </p>
            </div>
        </div>

        <!-- Smooth scrolling -->
        <script @cspNonce>
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) target.scrollIntoView({ behavior: 'smooth' });
                });
            });
        </script>

    </div>
@endsection

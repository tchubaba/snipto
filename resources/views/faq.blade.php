@extends('layouts.main')

@section('content')
    <div class="my-auto max-w-3xl mx-auto py-12 px-6 text-gray-900 dark:text-gray-100 leading-relaxed">

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
                <li><a href="#snipto-id-identity" class="text-indigo-600 dark:text-indigo-400 hover:underline">{!! __('How is a Snipto ID generated? Is it linked to my identity?') !!}</a></li>
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
                        {!! __('The key is derived from the password you provide. We only store a verification token computed in your browser from your password — the actual password <strong>never leaves your browser</strong>.') !!}
                    </li>
                    <li>
                        <strong>{!! __('Snipto ID (Asymmetric):') !!}</strong>
                        {!! __('The sender encrypts using the recipient\'s public Snipto ID. Only the recipient can decrypt by re-deriving their private key from their passphrase. The private key <strong>never leaves the browser</strong>.') !!}
                    </li>
                </ul>
                <p class="mt-4">
                    {!! __('In all cases, only someone with the correct key can decrypt the Snipto.') !!}
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
                    {!! __('If a snipto is never opened, it will be automatically deleted when it reaches its expiration time. By default, this happens one hour after creation. However, password-protected and Snipto ID snippets can be configured to last for up to one week. Sniptos are meant to be temporary, and shorter time-to-live settings help keep your data more secure.') !!}
                </p>
            </div>

            <div id="lost-key" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-3 text-indigo-600 dark:text-indigo-400">{!! __('What if the decryption key is lost?') !!}</h2>
                <p>
                    {!! __('If you lose the key (the <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">#</code> part of the URL, the password you chose, or the passphrase behind your Snipto ID), the Snipto cannot be decrypted. Since we never store or know your keys, passwords or passphrases, we cannot help you recover them. <strong>No key, no decryption.</strong>') !!}
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

            <div id="snipto-id-identity" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('How is a Snipto ID generated? Is it linked to my identity?') !!}</h2>
                <p class="mt-2">
                    {!! __('A Snipto ID is a 64-character pseudonymous identifier you create from a passphrase. When you click "Generate Snipto ID", your browser combines your passphrase with a fresh random value (a salt) to derive a cryptographic key pair. The ID you see is your public key plus that salt, encoded as text. The matching private key (needed to decrypt messages sent to your ID) is never stored anywhere; it is regenerated on demand from your passphrase.') !!}
                </p>
                <p class="mt-2">
                    {!! __('<strong>No part of this process touches our servers.</strong> The ID, your passphrase, and the derived keys never leave your device. We have no record of which Snipto IDs exist, who created them, or when. Even when someone later uses your ID to send you an encrypted Snipto, what reaches our servers is only the encrypted payload and an ephemeral key; nothing about it identifies you, your IP address, or your device. <strong>A Snipto ID is not an account, not a profile, and not linked to your real-world identity in any way.</strong>') !!}
                </p>
            </div>

            <div id="end-to-end" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-400">{!! __('How can I be sure my Snipto is end-to-end encrypted?') !!}</h2>
                <p class="mt-2">
                    {!! __('Your text is encrypted by <strong>your own browser</strong> before it is sent. What reaches our servers, and what sits in our database, is a block of unreadable characters. We have nothing to read, nothing to sell, and nothing to hand over.') !!}
                </p>
                <p class="mt-2">
                    {!! __('If you lose your key, we cannot recover your Snipto for you. That is not a policy choice; it is what end-to-end encryption means in practice: we never have the ability to read your Snipto, so there is nothing we could recover. The same property that makes a lost key final is what keeps your content private from everyone, including us.') !!}
                </p>
                <p class="mt-2">
                    {!! __('The code that encrypts inside your browser (<code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">snipto.js</code>) is delivered to you in clear, readable form, and the entire project is open source at') !!}
                    <a href="https://github.com/tchubaba/snipto" target="_blank" class="text-indigo-500 hover:underline">
                        https://github.com/tchubaba/snipto
                    </a>
                    {!! __('for anyone to inspect and verify. Independent security researchers can read exactly what your browser does before your data ever leaves your device.') !!}
                </p>
                <p class="mt-2">
                    {!! __('The one exception is the opt-in E2EE-disabled mode, which sends text as-is. That is why the app warns you whenever it is on.') !!}
                </p>
                <h3 class="mt-6 text-lg font-semibold text-indigo-600 dark:text-indigo-400">{!! __('For the technically curious') !!}</h3>
                <p class="mt-2">
                    {!! __('Open your browser\'s developer tools (F12), go to the Network tab, and create a Snipto: you will see that only ciphertext is sent to our servers. Want full control? Our official Docker Hub image, purpose-built for self-hosters, is available at') !!}
                    <a href="https://hub.docker.com/r/tchubaba/snipto" target="_blank" class="text-indigo-500 hover:underline">
                        https://hub.docker.com/r/tchubaba/snipto
                    </a>
                    {!! __('ready to pull and run: your own instance, your own infrastructure, and your data never touches our servers.') !!}
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

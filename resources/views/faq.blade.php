@extends('layouts.main')

@section('content')
    <div class="max-w-3xl mx-auto py-12 px-6 text-gray-900 dark:text-gray-100 leading-relaxed">

        <h1 class="text-4xl font-bold mb-10 text-center text-indigo-600 dark:text-indigo-300">Frequently Asked Questions</h1>

        <!-- FAQ Index -->
        <div class="mb-12">
            <ul class="space-y-2 list-disc list-inside text-lg">
                <li><a href="#what-is-snipto" class="text-indigo-600 dark:text-indigo-300 hover:underline">What is a Snipto?</a></li>
                <li><a href="#encryption-work" class="text-indigo-600 dark:text-indigo-300 hover:underline">How does encryption work?</a></li>
                <li><a href="#stored-servers" class="text-indigo-600 dark:text-indigo-300 hover:underline">What gets stored on your servers?</a></li>
                <li><a href="#encryption-key" class="text-indigo-600 dark:text-indigo-300 hover:underline">Where is the encryption key?</a></li>
                <li><a href="#viewed" class="text-indigo-600 dark:text-indigo-300 hover:underline">What happens to my Snipto after it’s viewed?</a></li>
                <li><a href="#never-viewed" class="text-indigo-600 dark:text-indigo-300 hover:underline">What happens if my snipto is never viewed?</a></li>
                <li><a href="#lost-key" class="text-indigo-600 dark:text-indigo-300 hover:underline">What if the decryption key is lost?</a></li>
                <li><a href="#content-types" class="text-indigo-600 dark:text-indigo-300 hover:underline">What kinds of content can I store?</a></li>
                <li><a href="#personal-info" class="text-indigo-600 dark:text-indigo-300 hover:underline">Is any personal information stored with my Snipto?</a></li>
                <li><a href="#end-to-end" class="text-indigo-600 dark:text-indigo-300 hover:underline">How can I be sure my Snipto is end-to-end encrypted?</a></li>
                <li><a href="#iv" class="text-indigo-600 dark:text-indigo-300 hover:underline">What’s an initialization vector (IV)?</a></li>
            </ul>
        </div>

        <div class="space-y-10">
            <div id="what-is-snipto" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-300">What is a Snipto?</h2>
                <p>
                    A Snipto is a small, private snippet of text that you can securely share with someone else. It’s like
                    a tiny secret note that only the people with the full URL and encryption key can read. Once viewed,
                    it vanishes automatically, so your message stays private and ephemeral.
                </p>
            </div>

            <div id="encryption-work" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-3 text-indigo-600 dark:text-indigo-300">How does encryption work?</h2>
                <p>
                    All encryption and decryption happens <strong>locally in your browser</strong>.
                    When you create a Snipto, your device encrypts the content before it ever leaves
                    your computer or phone.
                </p>
            </div>

            <div id="stored-servers" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-3 text-indigo-600 dark:text-indigo-300">What gets stored on your servers?</h2>
                <p>
                    We only store the <strong>encrypted form</strong> of your Snipto in our database.
                    This means we never see the plain text. Without the encryption key, the stored data
                    is meaningless to us.
                </p>
            </div>

            <div id="encryption-key" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-300">Where is the encryption key?</h2>
                <p>
                    The encryption key is included in the URL after the <code>#</code> symbol (called the URL fragment). This part of the URL is <strong>never sent to our servers</strong>, so only you or anyone you share the full URL with can decrypt the Snipto.
                    Each Snipto gets its <strong>own randomly generated encryption key</strong>, and the full URL—including this key—is shown to you immediately after creating the Snipto. Make sure to save or share it, because without full URL, the Snipto cannot be decrypted.
                </p>
            </div>

            <div id="viewed" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-3 text-indigo-600 dark:text-indigo-300">What happens to my Snipto after it’s viewed?</h2>
                <p>
                    Sniptos are <strong>ephemeral by default</strong>. Once decrypted and viewed,
                    they are automatically deleted permanently and cannot be retrieved again.
                </p>
            </div>

            <div id="never-viewed" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-3 text-indigo-600 dark:text-indigo-300">What happens if my snipto is never viewed?</h2>
                <p>
                    If a snipto is never opened, it will be automatically deleted one week after creation.
                </p>
            </div>

            <div id="lost-key" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-3 text-indigo-600 dark:text-indigo-300">What if the decryption key is lost?</h2>
                <p>
                    If you lose the key (the <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">#</code> part of the URL), the Snipto cannot be
                    decrypted. Since we never store or know your key, we cannot help you recover it.
                    <strong>No key, no decryption.</strong>
                </p>
            </div>

            <div id="content-types" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-3 text-indigo-600 dark:text-indigo-300">What kinds of content can I store?</h2>
                <p>
                    Currently, Snipto only supports <strong>plain text snippets</strong>.
                    In the future, we may expand support to other types of content while
                    maintaining end-to-end encryption.
                </p>
            </div>

            <div id="personal-info" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-300">Is any personal information stored or collected with my Snipto?</h2>
                <p>
                    No personal identifiable information is stored or collected with your Snipto. We only store the
                    <strong>ciphertext</strong> (your Snipto in encrypted form), the <strong>initialization vector (IV)</strong>, and
                    metadata about expiration and whether the Snipto has been viewed. Your IP address or any other
                    identifying info is <strong>never stored</strong> with your Snipto.
                </p>
            </div>

            <div id="end-to-end" class="scroll-mt-13">
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-300">How can I be sure my Snipto is end-to-end encrypted?</h2>
                <p class="mt-2">
                    All encryption and decryption happens <strong>directly in your browser</strong>. The JavaScript code
                    that performs these operations (snipto.js) is not minified and fully readable for transparency.
                </p>
                <p class="mt-2">
                    You can also inspect any network requests in your browser while creating or reading Sniptos. You will
                    see that only scrambled, unreadable ciphertext is ever sent to our servers — the plain text of your
                    Snipto and your encryption key are never transmitted or exposed.
                </p>
                <p class="mt-2">
                    Snipto is an <strong>open-source project</strong>. All source code is available at
                    <a href="https://github.com/tchubaba/snipto" target="_blank" class="text-indigo-500 hover:underline">
                        https://github.com/tchubaba/snipto
                    </a>
                    for anyone to inspect and verify. For those who want full control, you can also <strong>clone the
                    repository and self-host</strong> your own instance of Snipto, ensuring your data never touches
                    our servers.
                </p>
            </div>

            <div id="iv" class="scroll-mt-12">
                <h2 class="text-xl font-semibold mb-2 text-indigo-600 dark:text-indigo-300">What’s an initialization vector (IV)?</h2>
                <p>
                    When your Snipto is encrypted, we don’t just scramble the text in the same way every time. To make
                    it extra secure, a random “starting point” called an initialization vector (IV) is used. Think of
                    it like adding a pinch of unique spice to each batch of cookies — even if the recipe is the same,
                    each batch turns out slightly different. The IV ensures that two Sniptos with the same content
                    don’t produce the same encrypted output. It’s stored alongside the encrypted Snipto so your browser
                    can use it to decrypt the text, but it doesn’t compromise security — only someone with the
                    encryption key can read the content.
                </p>
            </div>
        </div>

        <!-- Smooth scrolling -->
        <script>
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

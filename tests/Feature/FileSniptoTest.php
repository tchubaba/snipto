<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProtectionType;
use App\Models\Snipto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Testing\TestResponse;

class FileSniptoTest extends TestCase
{
    use RefreshDatabase;

    private function fileMetadata(string $slug, int $type = ProtectionType::Secret->value): array
    {
        return [
            'slug'            => $slug,
            'kind'            => 'file',
            'protection_type' => $type,
            'nonce'           => str_repeat('ab', 12),
            'key_hash'        => str_repeat('cd', 32),
            'expiration'      => '1h',
        ];
    }

    #[Test]
    public function fileModeStoreCreatesInvisibleRowAndReturnsUploadToken(): void
    {
        $res = $this->postJson('/api/snipto', $this->fileMetadata('fileslug1'));
        $res->assertOk()->assertJsonStructure(['success', 'slug', 'upload_token']);
        $res->assertJson(['success' => true, 'slug' => 'fileslug1']);

        $snipto = Snipto::whereSlug('fileslug1')->first();
        $this->assertSame('file', $snipto->kind);
        $this->assertNull($snipto->payload);
        $this->assertNull($snipto->payload_blob);
        $this->assertNotNull($snipto->upload_token_hash);
        $this->assertSame(
            hash('sha256', $res->json('upload_token')),
            $snipto->upload_token_hash
        );
    }

    #[Test]
    public function plaintextModeRejectsFileKind(): void
    {
        $this->postJson('/api/snipto', [
            'slug'            => 'plainfileslug1',
            'kind'            => 'file',
            'protection_type' => ProtectionType::Plaintext->value,
            'payload'         => 'ignored',
        ])->assertStatus(422);
        $this->assertNull(Snipto::whereSlug('plainfileslug1')->first());
    }

    /**
     * Raw-binary PUT helper. MakesHttpRequests::put() form-encodes; call()
     * accepts a literal body ($content) so we exercise the real octet-stream
     * path, header-carrying token and all.
     */
    private function putPayload(string $slug, string $body, ?string $token): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/octet-stream'];

        if ($token !== null) {
            $server['HTTP_X_UPLOAD_TOKEN'] = $token;
        }

        return $this->call('PUT', "/api/snipto/{$slug}/payload", [], [], [], $server, $body);
    }

    #[Test]
    public function uploadWithValidTokenStoresBlobAtomically(): void
    {
        $token = $this->postJson('/api/snipto', $this->fileMetadata('uploadslug1'))
            ->json('upload_token');
        $bytes = random_bytes(4096);

        $res = $this->putPayload('uploadslug1', $bytes, $token);
        $res->assertOk()->assertJson(['success' => true]);

        tap(Snipto::whereSlug('uploadslug1')->first(), function (Snipto $s) use ($bytes) {
            $this->assertSame($bytes, $s->payload_blob);
            $this->assertNull($s->upload_token_hash); // consumed
        });
    }

    #[Test]
    public function secondUploadAttemptWithSameTokenIsRejectedAndKeepsBlob(): void
    {
        $token = $this->postJson('/api/snipto', $this->fileMetadata('uploadslug2'))
            ->json('upload_token');
        $first = random_bytes(16);
        $this->putPayload('uploadslug2', $first, $token)->assertOk();

        // Token now consumed — a replay cannot overwrite or re-upload.
        $this->putPayload('uploadslug2', random_bytes(16), 'anything')->assertStatus(403);
        $this->assertSame($first, Snipto::whereSlug('uploadslug2')->first()->payload_blob);
    }

    #[Test]
    public function oversizedBodyIsRejectedAgainstConfiguredCap(): void
    {
        config(['snipto.max_file_bytes' => 1024, 'snipto.envelope_slack_bytes' => 64]);
        $token = $this->postJson('/api/snipto', $this->fileMetadata('uploadslug3'))
            ->json('upload_token');

        $this->putPayload('uploadslug3', random_bytes(2048), $token)->assertStatus(413);
        self::assertNull(Snipto::whereSlug('uploadslug3')->first()->payload_blob);
    }

    #[Test]
    public function putToUnknownSlugIsNotFound(): void
    {
        $this->putPayload('nonexistent', 'x', 'whatever')->assertStatus(404);
    }

    #[Test]
    public function incompleteUploadIsInvisibleToProbersAndRevealers(): void
    {
        $this->postJson('/api/snipto', $this->fileMetadata('hiddenfileslug'));
        $this->getJson('/api/snipto/hiddenfileslug')->assertStatus(404);
        $this->postJson('/api/snipto/hiddenfileslug/reveal', ['reveal_token' => 'whatever'])
            ->assertStatus(404);

        // The row must SURVIVE being probed — a slow 50 MiB PUT in flight must
        // not be nuked by a viewer probe.
        $this->assertNotNull(Snipto::whereSlug('hiddenfileslug')->first());
    }

    #[Test]
    public function completedFileRevealReturnsRawBytesAndSingleViewSemantics(): void
    {
        $token = $this->postJson('/api/snipto', $this->fileMetadata('dlfileslug'))
            ->json('upload_token');
        $bytes = random_bytes(8192);
        $this->putPayload('dlfileslug', $bytes, $token)->assertOk();

        $meta = $this->getJson('/api/snipto/dlfileslug')->assertOk()->assertJson(['kind' => 'file']);

        $res = $this->postJson('/api/snipto/dlfileslug/reveal', [
            'reveal_token' => $meta->json('reveal_token'),
            'key_hash'     => str_repeat('cd', 32),
        ]);
        $res->assertOk();
        $this->assertSame('application/octet-stream', $res->headers->get('Content-Type'));
        // One-shot (non-streamed) body per design decision 7 — assert the exact bytes.
        $this->assertSame($bytes, $res->getContent());
        $this->assertSame(0, Snipto::whereSlug('dlfileslug')->count()); // single view still deletes
    }

    /**
     * Boundary regression pin (plan Task 14): a full 50 MiB blob must survive
     * the database's max_allowed_packet. The historical 16 MiB MariaDB default
     * would have silently broken file sharing at exactly the cap; the raised
     * packet size in both compose files and the CI service container is the
     * fix, and this test is the tripwire if anyone lowers it again.
     *
     * Skipped on the local SQLite default (no packet semantics); it bites in
     * CI, where the feature suite runs against MariaDB.
     */
    #[Test]
    public function fiftyMiBRowSurvivesTheDatabasePacketLimit(): void
    {
        if (config('database.default') !== 'mariadb') {
            $this->markTestSkipped('packet-limit regression is MariaDB-specific');
        }

        $token = $this->postJson('/api/snipto', $this->fileMetadata('bigfileslug'))->json('upload_token');
        $bytes = random_bytes(50 * 1024 * 1024);

        $this->putPayload('bigfileslug', $bytes, $token)->assertOk();

        $stored = Snipto::whereSlug('bigfileslug')->first();
        $this->assertSame($bytes, $stored->payload_blob);
        $this->assertNull($stored->payload); // text column stays untouched for file rows
    }

    /**
     * A file-mode store against a slug whose previous attempt never completed
     * its PUT replaces the invisible row in place (fresh upload token) instead
     * of locking the slug behind a 422 until purge. Without this, any failed
     * upload (network drop, size rejection) dead-ends the creator: the slug is
     * consumed, the row is invisible (404), and there is no DELETE endpoint.
     */
    #[Test]
    public function secondFileStoreOnIncompleteRowResetsItAndReissuesUploadToken(): void
    {
        $first = $this->postJson('/api/snipto', $this->fileMetadata('resetslug'));
        $first->assertOk();

        // While the first upload token is still active (unburned & unexpired),
        // an unauthenticated third party cannot hijack the in-flight upload...
        $this->postJson('/api/snipto', $this->fileMetadata('resetslug'))->assertStatus(422);

        // ...but the creator presenting their active upload_token CAN reset it:
        $second = $this->postJson('/api/snipto', array_merge(
            $this->fileMetadata('resetslug'),
            ['upload_token' => $first->json('upload_token')]
        ));
        $second->assertOk()->assertJsonStructure(['upload_token']);
        $this->assertNotSame($first->json('upload_token'), $second->json('upload_token'));
        $this->assertSame(1, Snipto::whereSlug('resetslug')->count());

        // If a PUT attempt fails after burning the token (e.g. empty body 413),
        // the creator can also reset the row without needing a live token:
        $this->putPayload('resetslug', '', $second->json('upload_token'))->assertStatus(413);
        $third = $this->postJson('/api/snipto', $this->fileMetadata('resetslug'));
        $third->assertOk()->assertJsonStructure(['upload_token']);

        // New token completes the upload...
        $this->putPayload('resetslug', 'fresh-bytes', $third->json('upload_token'))->assertOk();
        $this->assertSame('fresh-bytes', Snipto::whereSlug('resetslug')->first()->payload_blob);

        // ...the stale token from the abandoned attempt can neither 403-overwrite
        // nor replay: completed rows are immutable to upload().
        $this->putPayload('resetslug', 'stale-bytes', $first->json('upload_token'))->assertStatus(403);
        $this->assertSame('fresh-bytes', Snipto::whereSlug('resetslug')->first()->payload_blob);
    }

    #[Test]
    public function putToLiveTextSniptoReturns404AndDoesNotDeleteIt(): void
    {
        $this->postJson('/api/snipto', [
            'slug'            => 'textsurvives1',
            'payload'         => 'do-not-delete-me',
            'protection_type' => ProtectionType::Plaintext->value,
        ])->assertOk();

        // An unauthenticated PUT /payload against a live text snipto must
        // return 404 WITHOUT deleting the text snipto.
        $this->putPayload('textsurvives1', 'malicious-bytes', 'any-token')->assertStatus(404);

        $snipto = Snipto::whereSlug('textsurvives1')->first();
        $this->assertNotNull($snipto);
        $this->assertSame('do-not-delete-me', $snipto->payload);
    }

    #[Test]
    public function invalidUploadTokenReturns403WithoutBurningActiveUploadToken(): void
    {
        $token = $this->postJson('/api/snipto', $this->fileMetadata('noburnslug'))
            ->json('upload_token');

        // A third party probing PUT /payload with a wrong token gets 403...
        $this->putPayload('noburnslug', 'attacker-bytes', 'wrong-token')->assertStatus(403);
        $this->putPayload('noburnslug', 'attacker-bytes', null)->assertStatus(403);

        // ...and the legitimate creator's token still works to complete the upload.
        $this->putPayload('noburnslug', 'legit-bytes', $token)->assertOk();
        $this->assertSame('legit-bytes', Snipto::whereSlug('noburnslug')->first()->payload_blob);
    }

    /**
     * The replace window applies ONLY to incomplete file rows. Completed file
     * rows and text rows keep the classic 422 slug-taken semantics — an
     * attacker cannot clobber a payload that already landed.
     */
    #[Test]
    public function completedFileRowsAndTextRowsStillRejectSecondStore(): void
    {
        // Text row squatting blocked.
        $this->postJson('/api/snipto', [
            'slug'            => 'lockedslug1',
            'payload'         => 'hello',
            'protection_type' => ProtectionType::Plaintext->value,
        ])->assertOk();
        $this->postJson('/api/snipto', $this->fileMetadata('lockedslug1'))->assertStatus(422);

        // Completed file row squatting blocked.
        $tok = $this->postJson('/api/snipto', $this->fileMetadata('lockedslug2'))->json('upload_token');
        $this->putPayload('lockedslug2', 'x', $tok)->assertOk();
        $this->postJson('/api/snipto', $this->fileMetadata('lockedslug2'))->assertStatus(422);
    }
}

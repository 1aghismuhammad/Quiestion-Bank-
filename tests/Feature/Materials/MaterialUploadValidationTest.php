<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Actions\Materials\CreateUploadMaterial;
use App\Actions\Materials\GuardUploadStorageQuota;
use App\Actions\Subscriptions\ResolveUserEntitlement;
use App\Contracts\Materials\MaterialFileStore;
use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Http\Requests\Materials\StoreUploadMaterialRequest;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\Support\Materials\FakeMaterialFileStore;
use Tests\TestCase;

class MaterialUploadValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_upload_request_accepts_pdf_docx_and_txt_within_size_limit(): void
    {
        foreach ($this->allowedUploads() as $file) {
            $validator = Validator::make(
                ['title' => 'Materi', 'file' => $file],
                (new StoreUploadMaterialRequest)->rules(),
            );

            $this->assertFalse($validator->fails(), $validator->errors()->toJson());
        }
    }

    public function test_upload_request_rejects_missing_title_and_file(): void
    {
        $validator = Validator::make([], (new StoreUploadMaterialRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('title', $validator->errors()->toArray());
        $this->assertArrayHasKey('file', $validator->errors()->toArray());
    }

    public function test_upload_request_rejects_disallowed_extension(): void
    {
        $validator = Validator::make(
            [
                'title' => 'Materi',
                'file' => UploadedFile::fake()->create('notes.exe', 20, 'application/x-msdownload'),
            ],
            (new StoreUploadMaterialRequest)->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('file', $validator->errors()->toArray());
    }

    public function test_upload_request_rejects_allowed_mime_with_disallowed_filename_extension(): void
    {
        $validator = Validator::make(
            [
                'title' => 'Materi',
                'file' => UploadedFile::fake()->create('material.exe', 20, 'application/pdf'),
            ],
            (new StoreUploadMaterialRequest)->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('file', $validator->errors()->toArray());
        $this->assertTrue(
            collect($validator->errors()->get('file'))->contains(
                fn (string $message): bool => str_contains(strtolower($message), 'extension'),
            ),
        );
    }

    public function test_upload_request_rejects_allowed_filename_extension_with_disallowed_mime(): void
    {
        $validator = Validator::make(
            [
                'title' => 'Materi',
                'file' => UploadedFile::fake()->create('material.pdf', 20, 'application/x-msdownload'),
            ],
            (new StoreUploadMaterialRequest)->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('file', $validator->errors()->toArray());
        $this->assertTrue(
            collect($validator->errors()->get('file'))->contains(
                fn (string $message): bool => str_contains(strtolower($message), 'type'),
            ),
        );
    }

    public function test_upload_request_rejects_files_over_ten_megabytes(): void
    {
        $validator = Validator::make(
            [
                'title' => 'Materi',
                'file' => UploadedFile::fake()->create(
                    'notes.pdf',
                    StoreUploadMaterialRequest::MAX_FILE_SIZE_KILOBYTES + 1,
                    'application/pdf',
                ),
            ],
            (new StoreUploadMaterialRequest)->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('file', $validator->errors()->toArray());
    }

    public function test_upload_request_rejects_empty_files(): void
    {
        $validator = Validator::make(
            [
                'title' => 'Materi',
                'file' => UploadedFile::fake()->create('notes.pdf', 0, 'application/pdf'),
            ],
            (new StoreUploadMaterialRequest)->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('file', $validator->errors()->toArray());
    }

    public function test_upload_action_persists_contract_metadata_as_draft_pending(): void
    {
        $user = User::factory()->create();
        $store = new FakeMaterialFileStore;
        $file = UploadedFile::fake()->create('lesson.pdf', 120, 'application/pdf');

        $material = $this->action($store)->handle($user, 'Lesson PDF', $file);

        $this->assertSame(['inspect', 'store'], $store->calls);
        $this->assertSame(SourceType::UPLOAD, $material->source_type);
        $this->assertSame(ExtractionStatus::PENDING, $material->extraction_status);
        $this->assertSame(MaterialStatus::DRAFT, $material->status);
        $this->assertSame('lesson.pdf', $material->file_name);
        $this->assertSame($store->hash, $material->file_hash);
        $this->assertSame('application/pdf', $material->mime_type);
        $this->assertNotNull($material->file_path);
        $this->assertStringStartsWith($user->id.'/', $material->file_path);
        $this->assertStringNotContainsString('materials/', (string) $material->file_path);
        $this->assertNull($material->content);
        $this->assertSame([], $store->deleted);
    }

    public function test_upload_action_rejects_duplicate_hash_without_storing(): void
    {
        $user = User::factory()->create();
        $hash = hash('sha256', 'duplicate-upload');
        Material::factory()->upload()->for($user)->create(['file_hash' => $hash]);

        $store = new FakeMaterialFileStore($hash);

        try {
            $this->action($store)->handle(
                $user,
                'Duplicate',
                UploadedFile::fake()->create('lesson.pdf', 120, 'application/pdf'),
            );
            $this->fail('Expected duplicate uploads to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }

        $this->assertSame(['inspect'], $store->calls);
        $this->assertDatabaseCount('materials', 1);
    }

    public function test_same_file_hash_is_allowed_for_a_different_user(): void
    {
        $hash = hash('sha256', 'shared-hash');
        Material::factory()->upload()->create(['file_hash' => $hash]);

        $user = User::factory()->create();
        $store = new FakeMaterialFileStore($hash);

        $material = $this->action($store)->handle(
            $user,
            'Shared hash',
            UploadedFile::fake()->create('lesson.pdf', 120, 'application/pdf'),
        );

        $this->assertSame($hash, $material->file_hash);
        $this->assertDatabaseCount('materials', 2);
    }

    public function test_upload_action_rejects_missing_owner_before_store(): void
    {
        $store = new FakeMaterialFileStore;
        $missingOwner = User::factory()->make(['id' => 9_999_999]);
        $missingOwner->exists = true;

        try {
            $this->action($store)->handle(
                $missingOwner,
                'Broken persist',
                UploadedFile::fake()->create('lesson.pdf', 120, 'application/pdf'),
            );
            $this->fail('Expected persistence to fail for a missing owner.');
        } catch (ModelNotFoundException) {
            $this->assertSame(['inspect'], $store->calls);
            $this->assertSame([], $store->deleted);
        }

        $this->assertDatabaseCount('materials', 0);
    }

    public function test_upload_action_does_not_use_storage_or_hashing(): void
    {
        $source = (string) file_get_contents(base_path('app/Actions/Materials/CreateUploadMaterial.php'));

        $this->assertStringNotContainsString('Storage::', $source);
        $this->assertStringNotContainsString('Illuminate\\Support\\Facades\\Storage', $source);
        $this->assertStringNotContainsString('hash(', $source);
        $this->assertStringNotContainsString('hash_file', $source);
        $this->assertStringNotContainsString('getClientOriginalName', $source);
        $this->assertSame(MaterialFileStore::class, (new \ReflectionClass(CreateUploadMaterial::class))
            ->getConstructor()
            ?->getParameters()[0]
            ->getType()
            ?->getName());
    }

    private function action(?MaterialFileStore $store = null): CreateUploadMaterial
    {
        return new CreateUploadMaterial(
            $store ?? $this->app->make(MaterialFileStore::class),
            $this->app->make(GuardUploadStorageQuota::class),
            $this->app->make(ResolveUserEntitlement::class),
        );
    }

    /**
     * @return list<UploadedFile>
     */
    private function allowedUploads(): array
    {
        return [
            UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
            UploadedFile::fake()->create(
                'notes.docx',
                20,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ),
            UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
        ];
    }
}

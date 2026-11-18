<?php

namespace TornadoEngine\Uploads\Tests;

use App\Harness\HarnessTask;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use TornadoEngine\Broker\Filter;
use TornadoEngine\Broker\Models\Person;
use TornadoEngine\Broker\Pseudonym;
use TornadoEngine\Chat\Models\Conversation;
use TornadoEngine\Uploads\Models\Upload;
use TornadoEngine\Uploads\Uploads;
use ZipArchive;

class UploadsTest extends TestCase
{
    use RefreshDatabase;

    private string $student = 'annabel.quince@students.school.test';

    /** @var list<array> boxes each render was asked to draw */
    private array $rendered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
        config(['uploads.require_volume' => false]);
        Setting::put('broker.refreshed_at', now()->toIso8601String());
        Person::create(['kind' => 'student', 'email' => $this->student, 'pseudonym' => Pseudonym::for($this->student), 'values' => [[$this->student, 'email'], ['Annabel Quince', 'name'], ['Annabel', 'given'], ['Quince', 'family']]]);
        app(Filter::class)->forget();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/claude/uploads'));
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function claudeText(Upload $upload): string
    {
        return (string) @file_get_contents(app(Uploads::class)->dir($upload).'/text.txt');
    }

    /** A screenshot whose OCR finds the student's name, and the given faces. */
    private function fakeImageTools(string $faces = '[]', string $logos = '[]'): void
    {
        // Two OCR passes; the second reads less.
        $line1 = [['text' => 'Student:', 'box' => [20, 30, 130, 24]], ['text' => 'Annabel', 'box' => [167, 36, 128, 24]], ['text' => 'Quince', 'box' => [310, 36, 108, 28]]];
        $line2 = [['text' => 'Bus', 'box' => [20, 150, 54, 23]], ['text' => '14', 'box' => [92, 150, 45, 27]]];
        $ocr = json_encode(['passes' => [[$line1, $line2], [$line2]]]);
        Process::fake([
            "*'ocr'*" => Process::result($ocr),
            "*'faces'*" => Process::result($faces),
            "*'logos'*" => Process::result($logos),
            "*'render'*" => function ($process) {
                $command = $process->command;
                $this->rendered[] = json_decode((string) file_get_contents(end($command)), true);
                file_put_contents($command[count($command) - 2], 'png bytes');

                return Process::result('{"ok": true}');
            },
        ]);
    }

    public function test_claude_gets_filtered_text_and_never_the_original(): void
    {
        $this->actingAs($this->admin())->post('/uploads', ['student' => 1, 'files' => [UploadedFile::fake()->createWithContent('Annabel Quince notes.csv', "name,email\nAnnabel Quince,{$this->student}\n")]])
            ->assertSessionHas('uploads_status', 'Uploaded 1 file.');

        $upload = Upload::first();
        Storage::disk('uploads')->assertExists($upload->path);
        $text = $this->claudeText($upload);
        $this->assertStringNotContainsString('Annabel', $text);
        $this->assertStringNotContainsString($this->student, $text);
        $this->assertStringContainsString(Pseudonym::for($this->student), $text);
        $this->assertSame(['text.txt'], array_keys($upload->forClaude()), 'neutral names: the file name has a name in it');
        $this->assertStringNotContainsString('Annabel', $upload->describeForClaude());
    }

    public function test_images_go_to_claude_with_names_covered(): void
    {
        $this->fakeImageTools();
        $response = $this->actingAs($this->admin())->postJson('/uploads', ['student' => 1, 'files' => [UploadedFile::fake()->createWithContent('roster.png', 'png bytes')]])->assertOk();

        $upload = Upload::first();
        $this->assertSame(['image.png', 'text.txt'], array_keys($upload->forClaude()));
        $this->assertSame([[103, 5, 256, 86], [256, 0, 216, 101]], $this->rendered[0]['black'], 'the name is covered (and around it), the rest is not');
        $this->assertSame(2, $response->json('uploads.0.covered'));
        $this->assertStringContainsString('Student: [name '.Pseudonym::for($this->student), $this->claudeText($upload));
        $this->assertNotNull($response->json('uploads.0.preview'));
        $this->actingAs($upload->user)->get(parse_url($response->json('uploads.0.preview'), PHP_URL_PATH))->assertOk();
    }

    public function test_faces_wait_for_the_uploader_and_are_covered_if_they_say_so(): void
    {
        $this->fakeImageTools('[[400, 40, 60, 60]]');
        $admin = $this->admin();
        $response = $this->actingAs($admin)->postJson('/uploads', ['student' => 0, 'files' => [UploadedFile::fake()->createWithContent('trip.jpg', 'jpeg bytes')]])->assertOk();
        $this->assertSame([true, 1], [$response->json('uploads.0.waiting'), $response->json('uploads.0.faces')]);

        $upload = Upload::first();
        $this->assertSame(['text.txt'], array_keys($upload->forClaude()), 'only the text until they decide');
        $this->assertSame([], end($this->rendered)['red'], 'the page draws the red boxes');
        $this->assertSame([[400, 40, 60, 60]], $response->json('uploads.0.boxes'));

        $this->actingAs($admin)->postJson($response->json('uploads.0.url.review'), ['cover' => true])->assertJson(['waiting' => false, 'covered' => 3]);
        $this->assertContains([400, 40, 60, 60], end($this->rendered)['black']);
        $this->assertSame(['image.png', 'text.txt'], array_keys($upload->fresh()->forClaude()));

        $this->actingAs($admin)->postJson($response->json('uploads.0.url.review'), ['cover' => false])->assertJson(['covered' => 2]);
        $this->assertNotContains([400, 40, 60, 60], end($this->rendered)['black'], 'not a student: left as it is');
    }

    public function test_the_uploader_can_remove_found_boxes_and_add_their_own(): void
    {
        $this->fakeImageTools('[[400, 40, 60, 60]]');
        $admin = $this->admin();
        $response = $this->actingAs($admin)->postJson('/uploads', ['student' => 0, 'files' => [UploadedFile::fake()->createWithContent('trip.jpg', 'jpeg bytes')]])->assertOk();

        $this->actingAs($admin)->postJson($response->json('uploads.0.url.review'), ['cover' => true, 'boxes' => [[10, 20, 30, 40]]])->assertJson(['waiting' => false, 'covered' => 3]);
        $black = end($this->rendered)['black'];
        $this->assertContains([10, 20, 30, 40], $black);
        $this->assertNotContains([400, 40, 60, 60], $black);

        $this->actingAs($admin)->postJson($response->json('uploads.0.url.review'), ['cover' => true, 'boxes' => [[1, 2, 3]]])->assertUnprocessable();
    }

    public function test_an_image_with_student_information_whose_text_cant_be_read_isnt_given_to_claude(): void
    {
        Process::fake(["*'ocr'*" => Process::result('{"error": "tesseract"}')]);
        $this->actingAs($this->admin())->post('/uploads', ['student' => 1, 'files' => [UploadedFile::fake()->createWithContent('scan.png', 'png bytes')]]);

        $upload = Upload::first();
        $this->assertSame([], $upload->forClaude());
        $this->assertSame('Couldn\'t read the text in this image, so it isn\'t given to Claude.', $upload->error);
    }

    public function test_office_files_give_their_text(): void
    {
        $docx = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($docx, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Meeting about Annabel Quince</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $xlsx = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip->open($xlsx, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<sst><si><t>Student</t></si><si><t>Annabel Quince</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1"><v>12</v></c></row><row r="2"><c r="A2" t="s"><v>1</v></c><c r="B2"><f>SUM(B1)</f><v>12</v></c></row></sheetData></worksheet>');
        $zip->close();

        $this->actingAs($this->admin())->post('/uploads', ['student' => 1, 'files' => [new UploadedFile($docx, 'minutes.docx', null, null, true), new UploadedFile($xlsx, 'grades.xlsx', null, null, true)]]);
        [$word, $excel] = Upload::orderBy('id')->get()->all();

        $this->assertStringContainsString('Meeting about [name '.Pseudonym::for($this->student), $this->claudeText($word));
        $this->assertStringContainsString("Student\t12\n[name ".Pseudonym::for($this->student), $this->claudeText($excel));
    }

    public function test_district_logos_are_looked_for(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/administration/uploads/logos', ['logo' => UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='))]);
        $this->assertCount(1, app(Uploads::class)->logoFiles());
        $this->actingAs($admin)->get('/administration/uploads')->assertSee('Logos to look for in images');

        $this->fakeImageTools(logos: '[[700, 150, 120, 120, 0]]');
        $this->actingAs($admin)->postJson('/uploads', ['student' => 0, 'files' => [UploadedFile::fake()->createWithContent('poster.png', 'png bytes')]])
            ->assertJson(['uploads' => [['waiting' => true, 'logos' => 1, 'faces' => 0]]]);
    }

    public function test_the_size_limit_is_a_setting_up_to_1024_mb(): void
    {
        $admin = $this->admin();
        $this->assertSame(100, Uploads::maxMb());
        $this->actingAs($admin)->from('/administration/uploads')->post('/administration/uploads', ['max_mb' => 2000])->assertSessionHasErrors('max_mb');
        $this->actingAs($admin)->post('/administration/uploads', ['max_mb' => 1]);
        $this->assertSame(1, Uploads::maxMb());

        $this->actingAs($admin)->from('/chat')->post('/uploads', ['student' => 0, 'files' => [UploadedFile::fake()->create('big.txt', 2048)]])->assertSessionHasErrors('files.0');
        $this->assertSame(0, Upload::count());
    }

    public function test_uploads_are_off_until_the_volume_keeps_originals_from_the_harness(): void
    {
        config(['uploads.require_volume' => true]);
        $this->actingAs($this->admin())->from('/chat')->post('/uploads', ['student' => 0, 'files' => [UploadedFile::fake()->createWithContent('a.txt', 'x')]])
            ->assertSessionHasErrors('files');
        $this->assertSame(0, Upload::count());
    }

    public function test_people_only_change_their_own(): void
    {
        $this->actingAs($this->admin())->post('/uploads', ['student' => 0, 'files' => [UploadedFile::fake()->createWithContent('mine.txt', 'x')]]);
        $other = $this->admin();
        $id = Upload::first()->id;
        $this->actingAs($other)->post("/uploads/{$id}/review", ['cover' => true])->assertNotFound();
        $this->actingAs($other)->get("/uploads/{$id}/preview")->assertNotFound();
        $this->actingAs($other)->delete("/uploads/{$id}")->assertNotFound();
        $this->actingAs(User::factory()->create())->post('/uploads', ['student' => 0, 'files' => [UploadedFile::fake()->createWithContent('a.txt', 'x')]])->assertForbidden();
    }

    public function test_chat_messages_carry_their_files_and_deleting_the_chat_deletes_them(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/uploads', ['student' => 1, 'files' => [UploadedFile::fake()->createWithContent('Annabel Quince plan.txt', 'Plan for Annabel Quince')]]);
        $upload = Upload::first();

        $this->actingAs($admin)->post('/chat', ['text' => 'Summarise this', 'uploads' => [$upload->id]]);
        $prompt = HarnessTask::latest('id')->first()->prompt;
        $this->assertStringContainsString('/workspace/web/storage/app/claude/uploads/'.$upload->id.'/text.txt', $prompt);
        $this->assertStringNotContainsString('Annabel', $prompt);

        $conversation = Conversation::first();
        $this->assertSame('chat', $upload->fresh()->source);
        $this->actingAs($admin)->get("/chat/{$conversation->id}")->assertSee('Annabel Quince plan.txt');

        Conversation::query()->update(['updated_at' => now()->subDays(20)]);
        Conversation::prune();
        $this->assertSame(0, Upload::count());
        Storage::disk('uploads')->assertMissing($upload->path);
        $this->assertDirectoryDoesNotExist(app(Uploads::class)->dir($upload));
    }
}

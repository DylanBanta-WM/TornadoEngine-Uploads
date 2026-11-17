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

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
        config(['uploads.require_volume' => false]);
        Setting::put('broker.refreshed_at', now()->toIso8601String());
        Person::create(['kind' => 'student', 'email' => $this->student, 'pseudonym' => Pseudonym::for($this->student), 'values' => [[$this->student, 'email'], ['Annabel Quince', 'name']]]);
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

    public function test_claude_gets_filtered_text_and_never_the_original(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/uploads', ['files' => [UploadedFile::fake()->createWithContent('Annabel Quince notes.csv', "name,email\nAnnabel Quince,{$this->student}\n")]])
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

    public function test_files_marked_as_having_student_information_give_claude_nothing(): void
    {
        $this->actingAs($this->admin())->post('/uploads', ['share' => 'none', 'files' => [UploadedFile::fake()->createWithContent('roster.txt', 'Annabel Quince')]]);
        $upload = Upload::first();

        $this->assertSame([], $upload->forClaude());
        $this->assertStringContainsString('not given to you', $upload->describeForClaude());

        $this->actingAs($upload->user)->post("/uploads/{$upload->id}/share", ['share' => 'text']);
        $this->assertSame(['text.txt'], array_keys($upload->fresh()->forClaude()));
    }

    public function test_an_image_with_a_face_waits_for_the_uploader_to_confirm(): void
    {
        Process::fake(['*tesseract*' => Process::result('Field trip, Annabel Quince'), '*faces.py*' => Process::result("2\n")]);
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/uploads', ['share' => 'image', 'files' => [UploadedFile::fake()->createWithContent('trip.jpg', 'jpeg bytes')]])->assertOk();
        $this->assertSame([2, true], [$response->json('uploads.0.faces'), $response->json('uploads.0.waiting')]);
        $upload = Upload::first();
        $this->assertSame(['text.txt'], array_keys($upload->forClaude()));
        $this->assertStringContainsString(Pseudonym::for($this->student), $this->claudeText($upload));

        $this->actingAs($admin)->postJson("/uploads/{$upload->id}/share", ['share' => 'image', 'confirm_faces' => true])->assertJson(['waiting' => false]);
        $this->assertSame(['image.jpg', 'text.txt'], array_keys($upload->fresh()->forClaude()));
    }

    public function test_an_image_without_faces_goes_to_claude_straight_away(): void
    {
        Process::fake(['*tesseract*' => Process::result(''), '*faces.py*' => Process::result("0\n")]);
        $this->actingAs($this->admin())->post('/uploads', ['share' => 'image', 'files' => [UploadedFile::fake()->createWithContent('chart.png', 'png bytes')]]);

        $this->assertSame(['image.png'], array_keys(Upload::first()->forClaude()));
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

        $admin = $this->admin();
        $this->actingAs($admin)->post('/uploads', ['files' => [new UploadedFile($docx, 'minutes.docx', null, null, true), new UploadedFile($xlsx, 'grades.xlsx', null, null, true)]]);
        [$word, $excel] = Upload::orderBy('id')->get()->all();

        $this->assertStringContainsString('Meeting about [name '.Pseudonym::for($this->student), $this->claudeText($word));
        $this->assertStringContainsString("Student\t12\n[name ".Pseudonym::for($this->student), $this->claudeText($excel));
    }

    public function test_the_size_limit_is_a_setting_up_to_1024_mb(): void
    {
        $admin = $this->admin();
        $this->assertSame(100, Uploads::maxMb());
        $this->actingAs($admin)->from('/administration/uploads')->post('/administration/uploads', ['max_mb' => 2000])->assertSessionHasErrors('max_mb');
        $this->actingAs($admin)->post('/administration/uploads', ['max_mb' => 1]);
        $this->assertSame(1, Uploads::maxMb());

        $this->actingAs($admin)->from('/uploads')->post('/uploads', ['files' => [UploadedFile::fake()->create('big.txt', 2048)]])->assertSessionHasErrors('files.0');
        $this->assertSame(0, Upload::count());
    }

    public function test_uploads_are_off_until_the_volume_keeps_originals_from_the_harness(): void
    {
        config(['uploads.require_volume' => true]);
        $this->actingAs($this->admin())->from('/uploads')->post('/uploads', ['files' => [UploadedFile::fake()->createWithContent('a.txt', 'x')]])
            ->assertSessionHasErrors('files');
        $this->assertSame(0, Upload::count());
    }

    public function test_people_only_see_and_change_their_own(): void
    {
        $this->actingAs($this->admin())->post('/uploads', ['files' => [UploadedFile::fake()->createWithContent('mine.txt', 'x')]]);
        $other = $this->admin();
        $this->actingAs($other)->post('/uploads/'.Upload::first()->id.'/share', ['share' => 'none'])->assertNotFound();
        $this->actingAs($other)->delete('/uploads/'.Upload::first()->id)->assertNotFound();
        $this->actingAs(User::factory()->create())->post('/uploads', ['files' => [UploadedFile::fake()->createWithContent('a.txt', 'x')]])->assertForbidden();
        $this->actingAs($other)->get('/uploads')->assertStatus(405);
    }

    public function test_chat_messages_carry_their_files_and_deleting_the_chat_deletes_them(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/uploads', ['files' => [UploadedFile::fake()->createWithContent('Annabel Quince plan.txt', 'Plan for Annabel Quince')]]);
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

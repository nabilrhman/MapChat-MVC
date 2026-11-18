<?php

namespace Tests;

use App\Messages\VoiceClip;
use PHPUnit\Framework\TestCase;

/**
 * Checking the mic button's recording before Groq turns it into text
 */
class VoiceClipTest extends TestCase
{
    /** Files a test wrote, removed after it */
    private array $files = [];

    public function tearDown(): void
    {
        array_map(unlink(...), $this->files);
    }

    public function testNoUploadSaysNothingWasRecorded(): void
    {
        $this->assertSame('Nothing was recorded.', VoiceClip::problemWith(null));
        $this->assertSame('Nothing was recorded.', VoiceClip::problemWith(['error' => UPLOAD_ERR_NO_FILE]));
    }

    public function testARecordingOverTheLimitIsTooLong(): void
    {
        $upload = $this->uploadOf($this->wavBytes(), size: VoiceClip::MAX_BYTES + 1);
        $this->assertSame('That recording is too long. Try a shorter one.', VoiceClip::problemWith($upload));

        $refusedByPhp = ['error' => UPLOAD_ERR_INI_SIZE, 'size' => 0, 'tmp_name' => ''];
        $this->assertSame('That recording is too long. Try a shorter one.', VoiceClip::problemWith($refusedByPhp));
    }

    public function testAFileThatIsNotSoundIsRefusedWhateverItIsCalled(): void
    {
        $upload = $this->uploadOf('<?php echo "hi";');

        $this->assertSame('That recording is not a sound file.', VoiceClip::problemWith($upload));
    }

    public function testASoundFileGoesToGroqUnderItsOwnExtension(): void
    {
        $upload = $this->uploadOf($this->wavBytes());

        $this->assertNull(VoiceClip::problemWith($upload));
        $clip = VoiceClip::fromUpload($upload);

        $this->assertSame($upload['tmp_name'], $clip->path);
        $this->assertSame('voice.wav', $clip->fileName());
    }

    /**
     * A $_FILES entry for these bytes, written to a file
     *
     * @param int|null $size What the upload says its size is; null for the real one
     */
    private function uploadOf(string $bytes, ?int $size = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'voice');
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        return ['error' => UPLOAD_ERR_OK, 'size' => $size ?? strlen($bytes), 'tmp_name' => $path];
    }

    /**
     * A tenth of a second of silence as a WAV file
     */
    private function wavBytes(): string
    {
        $samples = str_repeat("\0\0", 1600);
        $format = pack('vvVVvv', 1, 1, 16000, 32000, 2, 16);

        return 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVE'
            . 'fmt ' . pack('V', 16) . $format
            . 'data' . pack('V', strlen($samples)) . $samples;
    }
}

<?php

namespace Tests\Controllers;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\DrivesTheSite;
use Tests\Support\TestDb;

/**
 * Photo uploads on a site with its own Cloudinary account, over real HTTP
 * requests. The browser uploads to Cloudinary itself and sends the site
 * only the photo's address, so no request here reaches Cloudinary
 */
class PhotoUploadTest extends TestCase
{
    use DrivesTheSite;

    const EMAIL = 'photo@test.local';
    const PASSWORD = 'secret123';
    const CLOUD_NAME = 'mapchat-test-cloud';

    private PDO $db;

    public static function setUpBeforeClass(): void
    {
        self::startSite(['CLOUDINARY_CLOUD_NAME' => self::CLOUD_NAME, 'CLOUDINARY_UPLOAD_PRESET' => 'mapchat-test-preset']);
    }

    public static function tearDownAfterClass(): void
    {
        self::stopSite();
    }

    protected function setUp(): void
    {
        // The server is another process, so these rows are committed, not
        // rolled back; tearDown deletes them
        $this->db = TestDb::get();
        TestDb::createUser('Pat', self::EMAIL, self::PASSWORD);
        $this->request('POST', '/login/create', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM userinfo WHERE email = ?')->execute([self::EMAIL]);
        $this->db->prepare('DELETE FROM users WHERE email = ?')->execute([self::EMAIL]);
    }

    /**
     * The saved photo address, or null for the drawn avatar
     */
    private function avatar(): ?string
    {
        $stmt = $this->db->prepare('SELECT avatar FROM users WHERE email = ?');
        $stmt->execute([self::EMAIL]);

        return $stmt->fetchColumn();
    }

    public function testTheProfileOffersChangePhotoWithTheSitesCloudinaryAccount(): void
    {
        $page = $this->request('GET', '/profile/show')['body'];

        $this->assertStringContainsString('id="change-photo"', $page);
        $this->assertStringContainsString('data-cloud-name="' . self::CLOUD_NAME . '"', $page);
        $this->assertStringContainsString('data-upload-preset="mapchat-test-preset"', $page);
        $this->assertStringNotContainsString("Photo uploads aren't set up", $page);
    }

    public function testAPhotoFromTheSitesCloudinaryAccountIsSaved(): void
    {
        $photo = 'https://res.cloudinary.com/' . self::CLOUD_NAME . '/image/upload/v1/pat.jpg';

        $this->assertSame(200, $this->request('POST', '/profile/update-avatar', ['avatar' => $photo])['status']);

        $this->assertSame($photo, $this->avatar());
    }

    public function testAPhotoFromAnyOtherAddressIsRefused(): void
    {
        foreach (['https://res.cloudinary.com/someone-else/image/upload/v1/pat.jpg', 'https://example.com/pat.jpg'] as $photo) {
            $this->assertSame(400, $this->request('POST', '/profile/update-avatar', ['avatar' => $photo])['status'], $photo);
        }

        $this->assertNull($this->avatar());
    }
}

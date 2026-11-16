<?php

namespace Tests;

use App\Config;
use PHPUnit\Framework\TestCase;

/**
 * Photo uploads need the site's own Cloudinary account: there's no default,
 * so a copy never uploads to someone else's
 */
class PhotoUploadSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('CLOUDINARY_CLOUD_NAME');
        putenv('CLOUDINARY_UPLOAD_PRESET');
    }

    public function testWithoutCloudinarySettingsThereIsNoAccountAndUploadsAreOff()
    {
        putenv('CLOUDINARY_CLOUD_NAME');
        putenv('CLOUDINARY_UPLOAD_PRESET');

        $this->assertNull(Config::cloudinaryCloudName());
        $this->assertNull(Config::cloudinaryUploadPreset());
        $this->assertFalse(Config::arePhotoUploadsOn());
    }

    public function testUploadsNeedBothTheCloudNameAndThePreset()
    {
        putenv('CLOUDINARY_CLOUD_NAME=my-cloud');
        $this->assertFalse(Config::arePhotoUploadsOn(), 'cloud name only');

        putenv('CLOUDINARY_CLOUD_NAME');
        putenv('CLOUDINARY_UPLOAD_PRESET=my-preset');
        $this->assertFalse(Config::arePhotoUploadsOn(), 'preset only');

        putenv('CLOUDINARY_CLOUD_NAME=my-cloud');
        $this->assertTrue(Config::arePhotoUploadsOn());
        $this->assertSame('my-cloud', Config::cloudinaryCloudName());
        $this->assertSame('my-preset', Config::cloudinaryUploadPreset());
    }
}

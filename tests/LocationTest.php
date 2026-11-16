<?php

namespace Tests;

use App\Models\UserInfo;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * A pin shows the city, never an exact location: saved and served to two
 * decimal places. Each test runs in a transaction that is rolled back
 */
class LocationTest extends TestCase
{
    const ANN = 'ann@test.local';

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = TestDb::get();
        $this->db->beginTransaction();

        $this->db->prepare("INSERT INTO users (name, email, password_hash, is_active) VALUES ('Ann', ?, '!', TRUE)")
            ->execute([self::ANN]);
        $this->db->prepare("INSERT INTO userinfo (email, ip, online_at) VALUES (?, '8.8.8.8', NOW())")
            ->execute([self::ANN]);
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
    }

    /**
     * Ann's pin as the map gets it
     */
    private function annsPin(): array
    {
        $pins = array_values(array_filter(UserInfo::getAllLocations(), fn ($pin) => $pin['name'] === 'Ann'));

        return $pins[0];
    }

    public function testALookedUpLocationIsSavedToTheCity()
    {
        UserInfo::saveLocation(self::ANN, ['lat' => 43.6532198, 'lng' => -79.3831843, 'city' => 'Toronto', 'country' => 'CA']);

        $stmt = $this->db->prepare('SELECT lat, lng FROM userinfo WHERE email = ?');
        $stmt->execute([self::ANN]);

        $this->assertEquals(['lat' => 43.65, 'lng' => -79.38], array_map(floatval(...), $stmt->fetch(PDO::FETCH_ASSOC)));
    }

    public function testAPinSavedBeforeRoundingIsServedToTheCity()
    {
        $this->db->prepare("UPDATE userinfo SET lat = 43.6532198, lng = -79.3831843, city = 'Toronto' WHERE email = ?")
            ->execute([self::ANN]);

        $pin = $this->annsPin();

        $this->assertSame(43.65, (float) $pin['lat']);
        $this->assertSame(-79.38, (float) $pin['lng']);
        $this->assertSame('43.65', (string) $pin['lat'], 'no more places than that');
    }

    public function testTheHttpsLocationServicesAreAskedWithoutAnIpstackKey()
    {
        putenv('IPSTACK_API_KEY');

        $this->assertSame(['ipapi.co', 'ipwho.is', 'geojs'], array_keys(UserInfo::locationServiceUrls('8.8.8.8')));
    }

    public function testIpstackIsAskedLastBecauseItsKeyGoesOverPlainHttp()
    {
        putenv('IPSTACK_API_KEY=test-key');

        try {
            $services = UserInfo::locationServiceUrls('8.8.8.8');
        } finally {
            putenv('IPSTACK_API_KEY');
        }

        $this->assertSame(['ipapi.co', 'ipwho.is', 'geojs', 'ipstack'], array_keys($services));
        $this->assertSame('http://api.ipstack.com/8.8.8.8?access_key=test-key', $services['ipstack']);
        foreach (['ipapi.co', 'ipwho.is', 'geojs'] as $name) {
            $this->assertStringStartsWith('https://', $services[$name], $name);
        }
    }

    public function testThePeopleListsLongitudeIsRoundedToo()
    {
        $this->db->prepare("UPDATE userinfo SET lat = 43.6532198, lng = -79.3831843, city = 'Toronto' WHERE email = ?")
            ->execute([self::ANN]);

        $people = array_values(array_filter(UserInfo::getRecentPeople(), fn ($person) => $person['name'] === 'Ann'));

        $this->assertSame('-79.38', (string) $people[0]['lng']);
    }
}

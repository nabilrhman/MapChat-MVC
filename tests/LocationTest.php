<?php

namespace Tests;

use App\Models\Whereabouts;
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

        TestDb::createUser('Ann', self::ANN);
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
        $pins = array_values(array_filter(Whereabouts::getAllLocations(), fn ($pin) => $pin['name'] === 'Ann'));

        return $pins[0];
    }

    public function testALookedUpLocationIsSavedToTheCity(): void
    {
        Whereabouts::saveLocation(self::ANN, ['lat' => 43.6532198, 'lng' => -79.3831843, 'city' => 'Toronto', 'country' => 'CA']);

        $stmt = $this->db->prepare('SELECT lat, lng FROM userinfo WHERE email = ?');
        $stmt->execute([self::ANN]);

        $this->assertEquals(['lat' => 43.65, 'lng' => -79.38], array_map(floatval(...), $stmt->fetch(PDO::FETCH_ASSOC)));
    }

    public function testAPinSavedBeforeRoundingIsServedToTheCity(): void
    {
        $this->db->prepare("UPDATE userinfo SET lat = 43.6532198, lng = -79.3831843, city = 'Toronto' WHERE email = ?")
            ->execute([self::ANN]);

        $pin = $this->annsPin();

        $this->assertSame(43.65, (float) $pin['lat']);
        $this->assertSame(-79.38, (float) $pin['lng']);
        $this->assertSame('43.65', (string) $pin['lat'], 'no more places than that');
    }

    public function testTheHttpsLocationServicesAreAskedWithoutAnIpstackKey(): void
    {
        putenv('IPSTACK_API_KEY');

        $this->assertSame(['ipapi.co', 'ipwho.is', 'geojs'], array_keys(Whereabouts::locationServiceUrls('8.8.8.8')));
    }

    public function testIpstackIsAskedLastBecauseItsKeyGoesOverPlainHttp(): void
    {
        putenv('IPSTACK_API_KEY=test-key');

        try {
            $services = Whereabouts::locationServiceUrls('8.8.8.8');
        } finally {
            putenv('IPSTACK_API_KEY');
        }

        $this->assertSame(['ipapi.co', 'ipwho.is', 'geojs', 'ipstack'], array_keys($services));
        $this->assertSame('http://api.ipstack.com/8.8.8.8?access_key=test-key', $services['ipstack']);
        foreach (['ipapi.co', 'ipwho.is', 'geojs'] as $name) {
            $this->assertStringStartsWith('https://', $services[$name], $name);
        }
    }

    public function testThePeopleListsLongitudeIsRoundedToo(): void
    {
        $this->db->prepare("UPDATE userinfo SET lat = 43.6532198, lng = -79.3831843, city = 'Toronto' WHERE email = ?")
            ->execute([self::ANN]);

        $people = array_values(array_filter(Whereabouts::getRecentPeople(), fn ($person) => $person['name'] === 'Ann'));

        $this->assertSame('-79.38', (string) $people[0]['lng']);
    }

    /**
     * The visitor's IP as getRealIP reads it, with these request details
     *
     * @param array<string, string> $server Entries of $_SERVER
     */
    private function realIpWith(array $server): string
    {
        $serverBefore = $_SERVER;
        try {
            unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
            $_SERVER = $server + $_SERVER;
            return Whereabouts::getRealIP();
        } finally {
            $_SERVER = $serverBefore;
        }
    }

    public function testAVisitorConnectingDirectlyCannotChooseTheirIpWithHeaders(): void
    {
        $this->assertSame('203.0.113.9', $this->realIpWith([
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_CF_CONNECTING_IP' => '8.8.8.8',
            'HTTP_X_FORWARDED_FOR' => '8.8.4.4',
        ]));
    }

    public function testBehindTheHostsProxyTheForwardedIpIsTheVisitors(): void
    {
        $this->assertSame('8.8.8.8', $this->realIpWith(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_CF_CONNECTING_IP' => '8.8.8.8']));
        $this->assertSame('8.8.4.4', $this->realIpWith(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 8.8.4.4']));
        $this->assertSame('10.1.2.3', $this->realIpWith(['REMOTE_ADDR' => '10.1.2.3']));
    }
}

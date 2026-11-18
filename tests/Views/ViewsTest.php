<?php

namespace Tests\Views;

use App\AvatarPicture;
use App\Models\User;
use Core\View;
use PHPUnit\Framework\TestCase;

/**
 * The account pages and emails render, and a refused form shows each
 * problem under its own field
 */
class ViewsTest extends TestCase
{
    public function testARefusedSignupShowsEachProblemUnderItsField(): void
    {
        $user = new User(['name' => '', 'email' => 'not an email', 'password' => 'abc']);
        $user->validate();

        $html = View::getTemplate('Signup/new.html', ['user' => $user]);

        $this->assertMatchesRegularExpression('/id="name-input-error">Enter your name\.</', $html);
        $this->assertMatchesRegularExpression('/id="email-input-error">Enter a valid email\.</', $html);
        $this->assertMatchesRegularExpression(
            '/id="password-input-error">Use 6 or more characters, with a letter and a number\.</',
            $html
        );
        $this->assertStringNotContainsString('Name:', $html);
    }

    public function testTheAccountPagesAndEmailsRender(): void
    {
        $pages = [
            'Signup/new.html' => [],
            'Login/new.html' => ['email' => 'ann@test.local', 'remember_me' => true],
            'Password/forgot.html' => [],
            'Password/reset.html' => ['token' => 'abc123'],
            'Password/reset_email.html' => ['url' => 'https://mapchat.test/password/reset/abc', 'name' => 'Ann'],
            'Signup/activation_email.html' => ['url' => 'https://mapchat.test/signup/activate/abc', 'name' => 'Ann'],
            '404.html' => [],
            '500.html' => [],
        ];

        foreach ($pages as $template => $args) {
            $this->assertStringContainsString('</html>', View::getTemplate($template, $args), $template);
        }
    }

    public function testTheLoginMapGetsADrawnFaceForEachDemoPerson(): void
    {
        $html = View::getTemplate('Login/new.html', ['email' => '', 'remember_me' => false]);

        preg_match('/data-faces="([^"]*)"/', $html, $match);
        $faces = json_decode(html_entity_decode($match[1] ?? ''), true);

        $this->assertCount(count(AvatarPicture::LOOKS), $faces);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $faces[0]);
    }
}

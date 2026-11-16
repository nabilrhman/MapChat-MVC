<?php

namespace Tests;

use App\Models\User;
use Core\View;
use PHPUnit\Framework\TestCase;

/**
 * The account pages and emails render, and a refused form shows each
 * problem under its own field
 */
class ViewsTest extends TestCase
{
    public function testARefusedSignupShowsEachProblemUnderItsField()
    {
        $user = new User(['name' => '', 'email' => 'not an email', 'password' => 'abc']);
        $user->validate();

        $html = View::getTemplate('Signup/new.html', ['user' => $user]);

        $this->assertMatchesRegularExpression('/id="inputName-error">Enter your name\.</', $html);
        $this->assertMatchesRegularExpression('/id="inputEmail-error">Enter a valid email\.</', $html);
        $this->assertMatchesRegularExpression(
            '/id="inputPassword-error">Your password needs at least 6 characters\. Your password needs a number\.</',
            $html
        );
        $this->assertStringNotContainsString('Name:', $html);
    }

    public function testTheAccountPagesAndEmailsRender()
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
}

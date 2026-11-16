<?php

namespace Core;

use App\Auth;
use App\Avatar;
use App\Config;
use App\Flash;
use App\Google;
use App\Mention;
use App\Models\Bot;
use App\Push;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Twig templates from App/Views, with the functions and values every page
 * uses
 */
class View
{
    /**
     * Print a template
     *
     * @param string $template The template's path under App/Views
     * @param array<string, mixed> $args Values for the template, by name
     */
    public static function renderTemplate(string $template, array $args = []): void
    {
        echo static::getTemplate($template, $args);
    }

    /**
     * A template, filled in
     *
     * @param string $template The template's path under App/Views
     * @param array<string, mixed> $args Values for the template, by name
     */
    public static function getTemplate(string $template, array $args = []): string
    {
        static $twig = null;

        $twig ??= static::createTwig();

        return $twig->render($template, $args);
    }

    /**
     * Twig, with the functions and globals the templates use
     */
    protected static function createTwig(): Environment
    {
        // Escape by file type: HTML for .html, none for .txt emails
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/App/Views'), ['autoescape' => 'name']);

        $twig->addGlobal('current_user', Auth::getUser());
        // A function, so only the page that shows messages uses them up
        $twig->addFunction(new TwigFunction('flash_messages', Flash::getMessages(...)));
        $twig->addFunction(new TwigFunction('avatar_src', Avatar::src(...)));
        // Links to files in public/ that change when the file does (see Asset)
        $twig->addFunction(new TwigFunction('asset', Asset::url(...)));
        $twig->addFunction(new TwigFunction('icon', Icon::svg(...), ['is_safe' => ['html']]));
        // @mentions in the open room, as chips (see App\Mention)
        $twig->addFunction(new TwigFunction('shown_name', Mention::shownName(...)));
        $twig->addFunction(new TwigFunction('mentioned_names', Mention::namesIn(...)));
        $twig->addFunction(new TwigFunction('mentions', Mention::isMentionedIn(...)));
        $twig->addFunction(new TwigFunction('mark_mentions', Mention::mark(...), ['is_safe' => ['html']]));
        $twig->addGlobal('app_url', Config::appUrl());
        $twig->addGlobal('csp_nonce', ContentSecurityPolicy::nonce());
        $twig->addGlobal('mapchat_can_reply', Bot::canReply());
        // For marking the current page in the header
        $twig->addGlobal('current_path', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $twig->addGlobal('cloudinary_cloud_name', Config::cloudinaryCloudName());
        $twig->addGlobal('cloudinary_upload_preset', Config::cloudinaryUploadPreset());
        // Empty while Web Push isn't set up, so pages don't offer it (see App\Push)
        // The login page offers Google only when the site has a client for it
        $twig->addGlobal('is_google_sign_in_on', Google::isEnabled());
        $twig->addGlobal('push_public_key', Push::isEnabled() ? Push::publicKey() : '');

        return $twig;
    }
}

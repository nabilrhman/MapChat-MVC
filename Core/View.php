<?php

namespace Core;

use App\Config;

/**
 * View
 */
class View
{
    /**
     * Render a view template using Twig
     *
     * @param string $template The template file
     * @param array $args Associative array of data to display in the view (optional)
     *
     * @return void
     */
    public static function renderTemplate($template, $args = [])
    {
        echo static::getTemplate($template, $args);
    }

    /**
     * Get the contents of a view template using Twig
     *
     * @param string $template The template file
     * @param array $args Associative array of data to display in the view (optional)
     *
     * @return string
     */
    public static function getTemplate($template, $args = [])
    {
        static $twig = null;

        if ($twig === null) {
            $loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/App/Views');
            // Escape by file type: HTML for .html, none for .txt emails
            $twig = new \Twig\Environment($loader, ['autoescape' => 'name']);
            $twig->addGlobal('current_user', \App\Auth::getUser());
            // A function, so only the page that shows messages uses them up
            $twig->addFunction(new \Twig\TwigFunction('flash_messages', [\App\Flash::class, 'getMessages']));
            $twig->addFunction(new \Twig\TwigFunction('avatar_src', [\App\Avatar::class, 'src']));
            // Links to files in public/ that change when the file does (see Asset)
            $twig->addFunction(new \Twig\TwigFunction('asset', [Asset::class, 'url']));
            $twig->addFunction(new \Twig\TwigFunction('icon', [Icon::class, 'svg'], ['is_safe' => ['html']]));
            // @mentions in the open room, as chips (see App\Mention)
            $twig->addFunction(new \Twig\TwigFunction('mentioned_names', [\App\Mention::class, 'namesIn']));
            $twig->addFunction(new \Twig\TwigFunction('mentions', [\App\Mention::class, 'has']));
            $twig->addFunction(new \Twig\TwigFunction('mark_mentions', [\App\Mention::class, 'mark'], ['is_safe' => ['html']]));
            $twig->addGlobal('app_url', Config::appUrl());
            $twig->addGlobal('mapchat_can_reply', \App\Models\Bot::canReply());
            // For marking the current page in the header
            $twig->addGlobal('current_path', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
            $twig->addGlobal('cloudinary_cloud_name', Config::cloudinaryCloudName());
            $twig->addGlobal('cloudinary_upload_preset', Config::cloudinaryUploadPreset());
        }

        return $twig->render($template, $args);
    }
}

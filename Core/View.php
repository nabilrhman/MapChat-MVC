<?php

namespace Core;

/**
 * View
 *
 * PHP version 7.0
 */
class View
{

    /**
     * Render a view file
     *
     * @param string $view The view file
     * @param array $args Associative array of data to display in the view (optional)
     *
     * @return void
     */
    public static function render($view, $args = [])
    {
        extract($args, EXTR_SKIP);

        $file = dirname(__DIR__) . "/App/Views/$view";  // relative to Core directory

        if (is_readable($file))
        {
            require $file;
        }
        else
        {
            throw new \Exception("$file not found");
        }
    }

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

        if ($twig === null)
        {
            $loader = new \Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/App/Views');
            // Escape by file type: HTML for .html, none for .txt emails
            $twig = new \Twig\Environment($loader, ['autoescape' => 'name']);
            $twig->addGlobal('current_user', \App\Auth::getUser());
            // A function, so only the page that shows messages uses them up
            $twig->addFunction(new \Twig\TwigFunction('flash_messages', [\App\Flash::class, 'getMessages']));
            $twig->addGlobal('app_url', \App\Config::appUrl());
            $twig->addGlobal('cloudinary_cloud_name', \App\Config::cloudinaryCloudName());
            $twig->addGlobal('cloudinary_upload_preset', \App\Config::cloudinaryUploadPreset());

        }

        return $twig->render($template, $args);
    }
}

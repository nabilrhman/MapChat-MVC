<?php

namespace Core;

use App\Config;

/**
 * Turns PHP errors into exceptions, and uncaught exceptions into an error
 * page and a log line
 */
class Error
{
    /**
     * Throw a PHP error as an ErrorException, so it stops the request like
     * any other failure
     *
     * @param int $level The error's E_* level
     *
     * @return bool False for an error silenced with @, so PHP carries on
     */
    public static function errorHandler(int $level, string $message, string $file, int $line): bool
    {
        // Respect @ (PHP 8 masks the level instead of zeroing it)
        if (!(error_reporting() & $level)) {
            return false;
        }

        throw new \ErrorException($message, 0, $level, $file, $line);
    }

    /**
     * Answer an uncaught exception: 405 for a wrong method, else a 404 or
     * 500 page, with the details shown (SHOW_ERRORS) or logged
     */
    public static function exceptionHandler(\Throwable $exception): void
    {
        // The caller's mistake, not an error to log
        if ($exception instanceof MethodNotAllowed) {
            http_response_code(405);
            header('Allow: ' . implode(', ', $exception->allowed));
            return;
        }

        $code = $exception->getCode() === 404 ? 404 : 500;
        http_response_code($code);

        if (Config::flag('SHOW_ERRORS')) {
            echo "<h1>Fatal error</h1>";
            echo "<p>Uncaught exception: '" . get_class($exception) . "'</p>";
            echo "<p>Message: '" . htmlspecialchars($exception->getMessage()) . "'</p>";
            echo "<p>Stack trace:<pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre></p>";
            echo "<p>Thrown in '" . htmlspecialchars($exception->getFile()) . "' on line " . $exception->getLine() . "</p>";
            return;
        }

        $message = "Uncaught exception: '" . get_class($exception) . "'";
        $message .= " with message '" . $exception->getMessage() . "'";
        $message .= "\nStack trace: " . $exception->getTraceAsString();
        $message .= "\nThrown in '" . $exception->getFile() . "' on line " . $exception->getLine();

        error_log($message);

        // Plain text if the error page itself fails (e.g. database down)
        try {
            View::renderTemplate("$code.html");
        } catch (\Throwable $e) {
            echo $code === 404 ? 'Page not found' : 'Something went wrong. Please try again in a moment.';
        }
    }
}

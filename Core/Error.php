<?php

namespace Core;

use App\Config;

/**
 * Error and exception handler
 */
class Error
{
    /**
     * Error handler. Convert all errors to Exceptions by throwing an ErrorException.
     *
     * @param int $level  Error level
     * @param string $message  Error message
     * @param string $file  Filename the error was raised in
     * @param int $line  Line number in the file
     *
     * @return void
     */
    public static function errorHandler($level, $message, $file, $line)
    {
        // Respect @ (PHP 8 masks the level instead of zeroing it)
        if (!(error_reporting() & $level)) {
            return false;
        }

        throw new \ErrorException($message, 0, $level, $file, $line);
    }

    /**
     * Exception handler.
     *
     * @param \Throwable $exception  The exception
     *
     * @return void
     */
    public static function exceptionHandler($exception)
    {
        // Code is 404 (not found) or 500 (general error)
        $code = $exception->getCode();
        if ($code != 404) {
            $code = 500;
        }
        http_response_code($code);

        if (Config::flag('SHOW_ERRORS')) {
            echo "<h1>Fatal error</h1>";
            echo "<p>Uncaught exception: '" . get_class($exception) . "'</p>";
            echo "<p>Message: '" . htmlspecialchars($exception->getMessage()) . "'</p>";
            echo "<p>Stack trace:<pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre></p>";
            echo "<p>Thrown in '" . htmlspecialchars($exception->getFile()) . "' on line " . $exception->getLine() . "</p>";
        } else {
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
}
